<?php

namespace App\WebsiteIntelligence;

use App\Crawling\UrlPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class PlaywrightScreenshotService
{
    public function __construct(private readonly UrlPolicy $policy) {}

    /** Capture the first-party page using a single validated, pinned public address. */
    public function capture(string $tenantId, string $scanId, string $pageId, string $url, ?int $timeoutSeconds = null): ?string
    {
        // This implementation launches Chromium as a subprocess from the current
        // Laravel worker. Production capture stays fail-closed until a separate
        // isolated browser-worker adapter is deployed.
        if (app()->environment('production')) return null;
        if (! config('crawling.playwright.enabled') || ! config('crawling.screenshots.enabled')) return null;

        [$host, $addresses] = $this->policy->validatePublicHttpUrl($url);
        $directory = storage_path('app/playwright/'.Str::uuid());
        $browserHome = storage_path('app/playwright-home');
        File::ensureDirectoryExists($directory, 0700, true);
        File::ensureDirectoryExists($browserHome, 0700, true);
        $hostHome = getenv('HOME') ?: '';
        $browserPath = config('crawling.playwright.browsers_path') ?: (PHP_OS_FAMILY === 'Darwin'
            ? $hostHome.'/Library/Caches/ms-playwright'
            : $hostHome.'/.cache/ms-playwright');
        $inheritedKeys = array_unique([...array_keys($_ENV), ...array_keys($_SERVER)]);
        $environment = array_replace(array_fill_keys($inheritedKeys, null), [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $browserHome,
            'TMPDIR' => $directory,
            'PLAYWRIGHT_BROWSERS_PATH' => $browserPath,
            'PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD' => '1',
            'LANG' => 'C.UTF-8',
        ]);

        try {
            $process = new Process([
                config('crawling.playwright.node_binary', 'node'),
                base_path('resources/playwright/capture.mjs'),
                $host,
                $addresses[0],
                $directory,
            ], base_path(), $environment, min((int) config('crawling.playwright.timeout_seconds', 45), max(1, $timeoutSeconds ?? 45)));
            $process->setInput($url);
            $process->run();

            $imagePath = $directory.'/desktop.png';
            $htmlPath = $directory.'/page.html';
            if (! $process->isSuccessful() || ! is_file($imagePath)) {
                Log::warning('Playwright screenshot capture unavailable.', ['tenant_id' => $tenantId, 'scan_id' => $scanId,
                    'exit_code' => $process->getExitCode(), 'error' => 'Capture process was unavailable or unsuccessful.']);
                return null;
            }

            $image = file_get_contents($imagePath);
            if ($image === false || strlen($image) > config('crawling.screenshots.max_bytes', 5_000_000)) return null;

            $objectKey = "tenants/{$tenantId}/crawls/{$scanId}/screenshots/{$pageId}-desktop.png";
            Storage::disk(config('filesystems.default'))->put($objectKey, $image);
            $existing = DB::table('website_screenshots')->where('tenant_id', $tenantId)->where('website_scan_id', $scanId)
                ->where('website_page_id', $pageId)->where('viewport', config('crawling.screenshots.viewport'))->first(['id', 'created_at']);
            $screenshotId = $existing?->id ?? (string) Str::uuid();
            DB::table('website_screenshots')->updateOrInsert(['id' => $screenshotId], [
                'tenant_id' => $tenantId, 'website_scan_id' => $scanId, 'website_page_id' => $pageId,
                'object_key' => $objectKey, 'viewport' => config('crawling.screenshots.viewport'),
                'content_hash' => hash('sha256', $image), 'captured_at' => now(), 'status' => 'stored',
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ]);

            return is_file($htmlPath) ? file_get_contents($htmlPath) ?: null : null;
        } catch (\Throwable $exception) {
            Log::warning('Playwright screenshot capture failed.', ['tenant_id' => $tenantId, 'scan_id' => $scanId,
                'exception' => $exception::class]);
            return null;
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
