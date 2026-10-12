<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CompanyHumanSalesReviewMigrationTest extends TestCase
{
    public function test_review_migration_applies_and_rolls_back_on_an_isolated_sqlite_connection(): void
    {
        $connection = 'company_sales_review_migration_test';
        config(['database.connections.'.$connection => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $original = DB::getDefaultConnection();
        DB::purge($connection);
        DB::setDefaultConnection($connection);

        try {
            Schema::create('tenants', fn (Blueprint $table) => $table->uuid('id')->primary());
            Schema::create('users', fn (Blueprint $table) => $table->id());
            Schema::create('companies', function (Blueprint $table): void {
                $table->uuid('id')->primary(); $table->uuid('tenant_id'); $table->unique(['tenant_id', 'id']);
            });
            Schema::create('tenant_services', function (Blueprint $table): void {
                $table->uuid('id')->primary(); $table->uuid('tenant_id'); $table->unique(['tenant_id', 'id']);
            });

            $migration = require database_path('migrations/2026_10_17_000002_create_company_human_sales_reviews.php');
            $migration->up();
            self::assertTrue(Schema::hasTable('company_human_sales_reviews'));
            self::assertTrue(Schema::hasColumns('company_human_sales_reviews', ['tenant_id', 'company_id', 'reviewer_id', 'decision', 'selected_service_id', 'reviewed_at']));

            $migration->down();
            self::assertFalse(Schema::hasTable('company_human_sales_reviews'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($connection);
        }
    }
}
