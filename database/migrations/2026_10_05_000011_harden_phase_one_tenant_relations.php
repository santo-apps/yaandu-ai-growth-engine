<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['company_websites', 'website_scans', 'website_pages', 'lead_insights', 'agent_runs'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique(['tenant_id', 'id'], $tableName.'_tenant_id_id_unique'));
        }
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->string('error_code', 64)->nullable();
            $table->uuid('correlation_id')->nullable()->index();
        });
        Schema::table('website_scans', fn (Blueprint $table) => $table->string('error_code', 64)->nullable());
        Schema::table('company_websites', fn (Blueprint $table) => $table->foreign(['tenant_id', 'company_id'], 'websites_tenant_company_fk')->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete());
        Schema::table('contacts', fn (Blueprint $table) => $table->foreign(['tenant_id', 'company_id'], 'contacts_tenant_company_fk')->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete());
        Schema::table('contact_methods', fn (Blueprint $table) => $table->foreign(['tenant_id', 'contact_id'], 'contact_methods_tenant_contact_fk')->references(['tenant_id', 'id'])->on('contacts')->cascadeOnDelete());
        Schema::table('website_scans', fn (Blueprint $table) => $table->foreign(['tenant_id', 'company_website_id'], 'scans_tenant_website_fk')->references(['tenant_id', 'id'])->on('company_websites')->cascadeOnDelete());
        Schema::table('website_pages', fn (Blueprint $table) => $table->foreign(['tenant_id', 'website_scan_id'], 'pages_tenant_scan_fk')->references(['tenant_id', 'id'])->on('website_scans')->cascadeOnDelete());
        Schema::table('website_screenshots', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'website_scan_id'], 'screenshots_tenant_scan_fk')->references(['tenant_id', 'id'])->on('website_scans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'website_page_id'], 'screenshots_tenant_page_fk')->references(['tenant_id', 'id'])->on('website_pages')->restrictOnDelete();
        });
        Schema::table('website_technologies', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'website_scan_id'], 'technologies_tenant_scan_fk')->references(['tenant_id', 'id'])->on('website_scans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'website_page_id'], 'technologies_tenant_page_fk')->references(['tenant_id', 'id'])->on('website_pages')->restrictOnDelete();
        });
        Schema::table('website_issues', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'website_scan_id'], 'issues_tenant_scan_fk')->references(['tenant_id', 'id'])->on('website_scans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'website_page_id'], 'issues_tenant_page_fk')->references(['tenant_id', 'id'])->on('website_pages')->restrictOnDelete();
        });
        Schema::table('lead_scores', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'company_id'], 'scores_tenant_company_fk')->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'], 'scores_tenant_run_fk')->references(['tenant_id', 'id'])->on('agent_runs')->restrictOnDelete();
        });
        Schema::table('lead_insights', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'company_id'], 'insights_tenant_company_fk')->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'], 'insights_tenant_run_fk')->references(['tenant_id', 'id'])->on('agent_runs')->restrictOnDelete();
        });
        Schema::table('lead_evidence', fn (Blueprint $table) => $table->foreign(['tenant_id', 'lead_insight_id'], 'evidence_tenant_insight_fk')->references(['tenant_id', 'id'])->on('lead_insights')->cascadeOnDelete());
        Schema::table('agent_events', fn (Blueprint $table) => $table->foreign(['tenant_id', 'agent_run_id'], 'events_tenant_run_fk')->references(['tenant_id', 'id'])->on('agent_runs')->cascadeOnDelete());
    }

    public function down(): void
    {
        Schema::table('agent_events', fn (Blueprint $table) => $table->dropForeign('events_tenant_run_fk'));
        Schema::table('lead_evidence', fn (Blueprint $table) => $table->dropForeign('evidence_tenant_insight_fk'));
        Schema::table('lead_insights', function (Blueprint $table): void { $table->dropForeign('insights_tenant_company_fk'); $table->dropForeign('insights_tenant_run_fk'); });
        Schema::table('lead_scores', function (Blueprint $table): void { $table->dropForeign('scores_tenant_company_fk'); $table->dropForeign('scores_tenant_run_fk'); });
        Schema::table('website_issues', function (Blueprint $table): void { $table->dropForeign('issues_tenant_page_fk'); $table->dropForeign('issues_tenant_scan_fk'); });
        Schema::table('website_technologies', function (Blueprint $table): void { $table->dropForeign('technologies_tenant_page_fk'); $table->dropForeign('technologies_tenant_scan_fk'); });
        Schema::table('website_screenshots', function (Blueprint $table): void { $table->dropForeign('screenshots_tenant_page_fk'); $table->dropForeign('screenshots_tenant_scan_fk'); });
        Schema::table('website_pages', fn (Blueprint $table) => $table->dropForeign('pages_tenant_scan_fk'));
        Schema::table('website_scans', fn (Blueprint $table) => $table->dropForeign('scans_tenant_website_fk'));
        Schema::table('contact_methods', fn (Blueprint $table) => $table->dropForeign('contact_methods_tenant_contact_fk'));
        Schema::table('contacts', fn (Blueprint $table) => $table->dropForeign('contacts_tenant_company_fk'));
        Schema::table('company_websites', fn (Blueprint $table) => $table->dropForeign('websites_tenant_company_fk'));
        Schema::table('agent_runs', function (Blueprint $table): void { $table->dropColumn(['error_code', 'correlation_id']); });
        Schema::table('website_scans', fn (Blueprint $table) => $table->dropColumn('error_code'));
        foreach (['company_websites', 'website_scans', 'website_pages', 'lead_insights', 'agent_runs'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique($tableName.'_tenant_id_id_unique'));
        }
    }
};
