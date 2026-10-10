<?php

namespace App\Console\Commands;

use App\SalesIntelligence\SalesIntelligenceMode;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Illuminate\Support\Str;
use Throwable;

final class ProductionReadiness extends Command
{
    protected $signature = 'production:readiness';
    protected $description = 'Report production readiness without exposing secrets; verifies local storage with a temporary probe';

    /** @var array<string, array{status:string, detail:string}> */
    private array $checks = [];

    public function handle(): int
    {
        $this->check('APP_ENV', app()->environment('production'), 'Production environment required.');
        $debugDisabled = config('app.debug') === false && ! config('production_readiness.debug_enabled', false);
        $this->check('APP_DEBUG', $debugDisabled, 'Debug must be disabled in the runtime and environment.');
        $this->check('APP_KEY', filled(config('app.key')), 'Application encryption key '.(filled(config('app.key')) ? 'present.' : 'missing.'));

        try {
            DB::select('select 1');
            $isPostgres = config('database.default') === 'pgsql';
            $this->check('PostgreSQL', $isPostgres, $isPostgres ? 'PostgreSQL connection succeeded.' : 'The configured database driver is not PostgreSQL.');
        } catch (Throwable) { $this->recordFailure('PostgreSQL', 'Database connection unavailable.'); }

        try { Redis::connection()->ping(); $this->pass('Redis', 'Redis connection succeeded.'); }
        catch (Throwable) { $this->recordFailure('Redis', 'Redis connection unavailable.'); }

        $queue = (string) config('queue.default');
        $productionSupervisors = config('horizon.environments.production', []);
        $queues = collect($productionSupervisors)->flatMap(fn ($supervisor) => $supervisor['queue'] ?? [])->unique()->all();
        $queueIsolated = $queue === 'redis' && collect(['default', 'discovery', 'candidate-discovery', 'intake', 'crawl', 'intelligence', 'scoring', 'campaigns', 'outbound', 'conversations', 'workflow'])->every(fn ($name) => in_array($name, $queues, true));
        $this->check('Queue configuration', $queueIsolated, 'Redis connection and explicit production supervisors for critical queues are required.');
        try {
            $running = collect(app(MasterSupervisorRepository::class)->all())->contains(fn ($master) => $master->status === 'running');
            $this->check('Horizon', $running, $running ? 'At least one Horizon master is running.' : 'No running Horizon master found.');
        } catch (Throwable) { $this->recordFailure('Horizon', 'Horizon status is unavailable.'); }

        $tenantId = (string) config('production_readiness.tenant_id', '');
        $this->check('OpenAI credential', filled(config('ai.providers.openai.key')), filled(config('ai.providers.openai.key')) ? 'OpenAI credential detected.' : 'OpenAI credential is not loaded.');
        if ($tenantId === '') {
            $this->recordWarning('Pilot tenant AI route', 'Set PRODUCTION_READINESS_TENANT_ID to verify the explicit website_reasoning route.');
            $this->recordWarning('Human-assisted mode', 'Tenant mode was not checked because PRODUCTION_READINESS_TENANT_ID is unset.');
        } else {
            try {
                $route = DB::table('ai_model_configurations')->where('tenant_id', $tenantId)->where('task_key', 'website_reasoning')->first();
                $provider = $route->provider ?? null;
                $credential = in_array($provider, ['openai', 'anthropic', 'gemini'], true) && filled(config('ai.providers.'.$provider.'.key'));
                $activeTenant = DB::table('tenants')->where('id', $tenantId)->where('status', 'active')->exists();
                $valid = $activeTenant && $route && $route->enabled && $provider === 'openai' && filled($route->model) && $credential;
                $this->check('Pilot tenant AI route', (bool) $valid, $valid ? 'Explicit enabled OpenAI website_reasoning route and credential detected.' : 'Explicit enabled OpenAI website_reasoning route and credential are required.');
                $mode = app(SalesIntelligenceMode::class)->forTenant($tenantId);
                $this->check('Human-assisted mode', $mode === SalesIntelligenceMode::HUMAN_ASSISTED && ! config('sales_intelligence.experimental_autonomous_enabled'), 'Mode: '.$mode.'; experimental autonomy must remain disabled.');
            } catch (Throwable) {
                $this->recordFailure('Pilot tenant AI route', 'Tenant configuration could not be inspected.');
                $this->recordFailure('Human-assisted mode', 'Tenant mode could not be inspected.');
            }
        }

        $playwrightEnabled = (bool) config('crawling.playwright.enabled', false);
        $this->check('Playwright worker', ! $playwrightEnabled,
            $playwrightEnabled ? 'Production Playwright is blocked until a dedicated isolated browser-worker adapter is deployed.' : 'Playwright is disabled; browser work is not enabled in application workers.');

        $importLimit = (int) config('pilot.max_import_rows', 0);
        $aiCallLimit = (int) config('ai.daily_call_limit', 0);
        $aiTokenLimit = (int) config('ai.daily_token_limit', 0);
        $this->check('Pilot processing limits', $importLimit >= 10 && $importLimit <= 25 && $aiCallLimit > 0 && $aiTokenLimit > 0,
            'Require 10–25 rows per batch and positive daily AI call/token caps.');

        $this->checkProductionStorage();

        $scheduled = count(app(Schedule::class)->events()) > 0;
        $this->check('Scheduler', $scheduled, $scheduled ? 'Application schedule contains events; host heartbeat still requires deployment verification.' : 'No scheduled tasks are registered.');
        if (! $scheduled || ! config('production_readiness.scheduler_heartbeat_configured')) $this->recordWarning('Scheduler heartbeat', 'External scheduler heartbeat is not verified.');
        $url = (string) config('app.url');
        $this->check('Public URL / HTTPS', filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://'), 'APP_URL must be a valid HTTPS URL.');
        $this->check('Secure session cookie', (bool) config('session.secure'), 'Secure session cookie must be enabled behind HTTPS.');
        if (config('production_readiness.trusted_edge_verified')) $this->pass('Trusted hosts / proxies', 'Deployment owner attests HTTPS edge, host allow-list and trusted proxy configuration.');
        else $this->recordWarning('Trusted hosts / proxies', 'Deployment proxy and host allow-list must be verified at the edge.');
        $this->checkMail();
        $this->check('Backups', (bool) config('production_readiness.backup_verified'), 'Backup schedule and isolated restore evidence are deployment-owned.');
        $this->check('Monitoring', (bool) config('production_readiness.monitoring_configured'), 'HTTP, DB, Redis, Horizon, queue and provider alerts are deployment-owned.');

        $this->newLine();
        $this->table(['CHECK', 'STATUS', 'DETAIL'], collect($this->checks)->map(fn ($check, $name) => [$name, $check['status'], $check['detail']])->all());
        $hasBlockers = collect($this->checks)->contains(fn ($check) => in_array($check['status'], ['FAIL', 'WARN'], true));
        $this->line($hasBlockers ? '<fg=red>Production readiness: NOT READY (FAIL or WARN gates remain)</>' : '<fg=green>Production readiness: READY</>');

        return $hasBlockers ? self::FAILURE : self::SUCCESS;
    }

    private function check(string $name, bool $passed, string $detail): void { $this->checks[$name] = ['status' => $passed ? 'PASS' : 'FAIL', 'detail' => $detail]; }
    private function pass(string $name, string $detail): void { $this->checks[$name] = ['status' => 'PASS', 'detail' => $detail]; }
    private function recordFailure(string $name, string $detail): void { $this->checks[$name] = ['status' => 'FAIL', 'detail' => $detail]; }
    private function recordWarning(string $name, string $detail): void { $this->checks[$name] = ['status' => 'WARN', 'detail' => $detail]; }

    private function checkProductionStorage(): void
    {
        $disk = (string) config('filesystems.default');

        if ($disk === 's3') {
            $configured = filled(config('filesystems.disks.s3.bucket'))
                && filled(config('filesystems.disks.s3.key'))
                && filled(config('filesystems.disks.s3.secret'));
            if (! $configured) {
                $this->recordFailure('Production storage', 'S3 is selected but required configuration is missing.');
            } elseif (config('production_readiness.storage_runtime_verified')) {
                $this->pass('Production storage', 'S3 is configured and deployment owner attests read/write/delete and private bucket verification.');
            } else {
                $this->recordWarning('Production storage', 'S3 configuration detected; runtime read/write/delete has not been verified.');
            }

            return;
        }

        if ($disk !== 'local') {
            $this->recordFailure('Production storage', 'Unsupported default storage disk: '.$disk.'. Select verified private local storage or S3.');

            return;
        }

        $privatePath = storage_path('app/private');
        $privateRealPath = realpath($privatePath);
        $publicRealPath = realpath(public_path());
        $diskRealPath = realpath((string) config('filesystems.disks.local.root', ''));

        if (! is_dir($privatePath) || $privateRealPath === false) {
            $this->recordFailure('Production storage', 'Required private storage directory storage/app/private does not exist.');

            return;
        }

        if ($publicRealPath === false || $privateRealPath === $publicRealPath
            || str_starts_with($privateRealPath, rtrim($publicRealPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            $this->recordFailure('Production storage', 'Private storage resolves inside the public web root.');

            return;
        }

        if ($diskRealPath === false || $diskRealPath !== $privateRealPath) {
            $this->recordFailure('Production storage', 'The local disk root must resolve to storage/app/private.');

            return;
        }

        if (! is_writable($privateRealPath)) {
            $this->recordFailure('Production storage', 'Private storage directory is not writable by the application.');

            return;
        }

        $filesystem = Storage::disk('local');
        $probePath = '.production-storage-probe-'.Str::uuid().'.tmp';
        $probeContent = Str::random(48);
        try {
            if (! $filesystem->put($probePath, $probeContent)
                || $filesystem->get($probePath) !== $probeContent
                || ! $filesystem->delete($probePath)
                || $filesystem->exists($probePath)) {
                throw new \RuntimeException('Storage probe operation failed.');
            }
        } catch (Throwable) {
            try { $filesystem->delete($probePath); } catch (Throwable) { /* Best-effort cleanup of a random probe file. */ }
            $this->recordFailure('Production storage', 'Private storage write/read/delete probe failed.');

            return;
        }

        if (! config('production_readiness.storage_runtime_verified')) {
            $this->recordFailure('Production storage', 'Local storage probe passed, but PRODUCTION_STORAGE_RUNTIME_VERIFIED is not enabled.');

            return;
        }

        $this->pass('Production storage', 'Private local storage path, permissions and runtime write/read/delete probe verified.');
    }

    private function checkMail(): void
    {
        if (! config('production_readiness.mail_enabled', false)) {
            $this->pass('Mail', 'Outbound mail is intentionally disabled for the controlled pilot.');

            return;
        }

        $mailer = strtolower(trim((string) config('mail.default', '')));
        $mailerConfig = config('mail.mailers.'.$mailer);
        $transport = is_array($mailerConfig) ? strtolower(trim((string) ($mailerConfig['transport'] ?? ''))) : '';
        $configured = $mailer !== '' && ! in_array($mailer, ['log', 'array'], true)
            && $transport !== '' && ! in_array($transport, ['log', 'array'], true);

        if ($configured && $transport === 'smtp') {
            $host = (string) ($mailerConfig['host'] ?? '');
            $port = filter_var($mailerConfig['port'] ?? null, FILTER_VALIDATE_INT);
            $configured = filled($host) && $port !== false && $port >= 1 && $port <= 65535;
        } elseif ($configured) {
            $mailerOptions = array_filter($mailerConfig, static fn (mixed $value, string|int $key): bool => $key !== 'transport' && filled($value), ARRAY_FILTER_USE_BOTH);
            $serviceOptions = config('services.'.$mailer, []);
            $configured = $mailerOptions !== [] || (is_array($serviceOptions) && $serviceOptions !== []);
        }

        if (! $configured) {
            $this->recordFailure('Mail', 'Outbound mail is enabled but no usable non-log/non-array transport configuration is present.');

            return;
        }

        try {
            // Resolve the configured driver without sending a message or opening a provider connection.
            Mail::mailer($mailer)->getSymfonyTransport();
        } catch (Throwable) {
            $this->recordFailure('Mail', 'Outbound mail is enabled but Laravel cannot resolve the configured transport.');

            return;
        }

        $this->pass('Mail', 'Mail transport is configured. Confirm provider delivery before enabling notifications; no delivery is assumed.');
    }
}
