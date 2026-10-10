<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const KEYS = [
        'website_modernization', 'ecommerce_development_migration', 'custom_software', 'mobile_applications',
        'ai_agents', 'whatsapp_automation_converiq', 'erp_workflow_automation', 'seo',
        'conversion_rate_optimization', 'digital_marketing', 'cloud_devops',
    ];

    public function up(): void
    {
        Schema::table('tenant_services', function ($table): void {
            $table->string('canonical_service_key', 64)->nullable();
        });

        $allowed = implode("','", self::KEYS);
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER tenant_services_canonical_key_insert BEFORE INSERT ON tenant_services
                WHEN NEW.canonical_service_key IS NOT NULL AND NEW.canonical_service_key NOT IN ('{$allowed}')
                BEGIN SELECT RAISE(ABORT, 'Invalid canonical service key'); END");
            DB::unprepared("CREATE TRIGGER tenant_services_canonical_key_update BEFORE UPDATE OF canonical_service_key ON tenant_services
                WHEN NEW.canonical_service_key IS NOT NULL AND NEW.canonical_service_key NOT IN ('{$allowed}')
                BEGIN SELECT RAISE(ABORT, 'Invalid canonical service key'); END");
        } else {
            DB::statement("ALTER TABLE tenant_services ADD CONSTRAINT tenant_services_canonical_service_key_check
                CHECK (canonical_service_key IS NULL OR canonical_service_key IN ('{$allowed}'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS tenant_services_canonical_key_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS tenant_services_canonical_key_update');
        } else {
            DB::statement('ALTER TABLE tenant_services DROP CONSTRAINT tenant_services_canonical_service_key_check');
        }

        Schema::table('tenant_services', function ($table): void {
            $table->dropColumn('canonical_service_key');
        });
    }
};
