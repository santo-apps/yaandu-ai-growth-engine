<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProspectImportRowJob;
use App\Models\Tenant;
use App\Models\User;
use App\Pilot\ProspectCsvImportService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class SeedSimulatedPilot extends Command
{
    protected $signature = 'product:pilot-seed-simulated';
    protected $description = 'Reset and seed the dedicated, fictional Sprint 7 pilot cohort for local UI acceptance.';

    private const TENANT_SLUG = 'sprint-7-simulated-pilot';
    private const USER_EMAIL = 'sprint-7-pilot@example.test';
    private const FIXTURE_TYPE = 'yaandu_sprint_7_simulated_pilot';

    public function handle(ProspectCsvImportService $imports): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('pilot.allow_simulated_fixtures', false)) {
            $this->error('Simulated pilot seeding requires APP_ENV=local/testing and PILOT_ALLOW_SIMULATED_FIXTURES=true.');
            return self::FAILURE;
        }

        try {
            $tenant = $this->resetTenant();
            $password = Str::random(48);
            $owner = $this->owner($password);
            $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
            $cohortId = (string) Str::uuid();
            DB::table('pilot_cohorts')->insert(['id' => $cohortId, 'tenant_id' => $tenant->id, 'name' => 'Sprint 7 fictional internal pilot · 25 prospects',
                'starts_on' => today(), 'owner_user_id' => $owner->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $path = tempnam(sys_get_temp_dir(), 'yaandu-s7-');
            if ($path === false) throw new LogicException('Unable to create a temporary local fixture CSV.');
            try {
                $stream = fopen($path, 'wb');
                fputcsv($stream, ['business_name', 'website', 'country', 'city', 'industry', 'business_email', 'contact_name', 'source', 'notes']);
                $countries = [['IN', 'Coimbatore'], ['IN', 'Chennai'], ['IN', 'Bengaluru'], ['AE', 'Dubai'], ['AE', 'Abu Dhabi']];
                $industries = ['Retail', 'Healthcare', 'Professional Services', 'Manufacturing', 'Hospitality'];
                for ($i = 1; $i <= 25; $i++) {
                    $domain = sprintf('sprint7-prospect-%02d.fixture.test', $i);
                    [$country, $city] = $countries[($i - 1) % count($countries)];
                    $email = $i <= 3 ? 'buyer'.sprintf('%02d', $i).'@'.$domain : '';
                    fputcsv($stream, ['SIMULATED · Business '.sprintf('%02d', $i), 'https://'.$domain, $country, $city, $industries[($i - 1) % count($industries)], $email,
                        $i <= 3 ? 'Simulated Buyer '.sprintf('%02d', $i) : '', 'sprint7_simulated_fixture', 'SIMULATED ONLY — fictional local test data, not a real prospect.']);
                }
                fputcsv($stream, ['SIMULATED · Duplicate Business', 'https://sprint7-prospect-01.fixture.test', 'IN', 'Coimbatore', 'Retail', '', '', 'sprint7_simulated_fixture', 'Duplicate test row.']);
                fputcsv($stream, ['SIMULATED · Unsafe URL', 'http://127.0.0.1/admin', 'IN', 'Chennai', 'Retail', '', '', 'sprint7_simulated_fixture', 'Unsafe URL test row.']);
                fputcsv($stream, ['SIMULATED · Invalid Country', 'https://invalid-country.fixture.test', 'ZZ', 'Dubai', 'Retail', '', '', 'sprint7_simulated_fixture', 'Invalid country test row.']);
                fclose($stream);
                $upload = new UploadedFile($path, 'sprint7-simulated-pilot.csv', 'text/csv', UPLOAD_ERR_OK, true);
                $preview = $imports->preview($tenant->id, (int) $owner->id, $upload, $cohortId);
                DB::table('prospect_import_batches')->where('tenant_id', $tenant->id)->where('id', $preview['id'])->update([
                    'status' => 'importing', 'idempotency_key' => 'sprint7-local-fixture-v1', 'confirmed_at' => now(), 'updated_at' => now(),
                ]);
                $rowIds = DB::table('prospect_import_rows')->where('tenant_id', $tenant->id)->where('batch_id', $preview['id'])->where('status', 'ready')->pluck('id');
                foreach ($rowIds as $rowId) (new ProcessProspectImportRowJob($tenant->id, $rowId, (string) $owner->id))->handle(app(\App\Contacts\ContactMethodValue::class));
            } finally { @unlink($path); }

            $this->info('Seeded 25 fictional Sprint 7 prospects in one local pilot cohort.');
            $this->line('1 duplicate and 2 invalid rows are retained for row-level review. Website domains remain user-supplied and unverified.');
            $this->line('No outbound messages, meetings, or proposals were created.');
            $this->line('Sign in: '.self::USER_EMAIL.' / '.$password.' (one-time local fixture password; not persisted in source)');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function resetTenant(): Tenant
    {
        $existing = Tenant::query()->where('slug', self::TENANT_SLUG)->first();
        if ($existing) {
            $settings = $existing->settings ?? [];
            if (($settings['fixture_type'] ?? null) !== self::FIXTURE_TYPE) throw new LogicException('The simulated-pilot tenant slug is occupied by unrecognized tenant data.');
            $existing->delete();
        }
        return Tenant::create(['name' => 'SIMULATED ONLY · Sprint 7 local pilot', 'slug' => self::TENANT_SLUG, 'status' => 'active',
            'settings' => ['fixture_type' => self::FIXTURE_TYPE, 'simulated' => true]]);
    }

    private function owner(string $password): User
    {
        $owner = User::query()->where('email', self::USER_EMAIL)->first();
        if ($owner && DB::table('tenant_user')->where('user_id', $owner->id)->exists()) throw new LogicException('The simulated-pilot owner is linked to a tenant; refusing to reuse it.');
        if (! $owner) $owner = new User(['name' => 'Sprint 7 Local Pilot Owner', 'email' => self::USER_EMAIL]);
        $owner->name = 'Sprint 7 Local Pilot Owner';
        $owner->password = Hash::make($password);
        $owner->save();
        return $owner;
    }
}
