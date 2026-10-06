<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_scheduling_configurations', function (Blueprint $table): void {
            $table->string('owner_timezone', 64)->default('UTC');
        });
        Schema::table('scheduling_requests', function (Blueprint $table): void {
            $table->string('owner_timezone', 64)->default('UTC');
        });
        \Illuminate\Support\Facades\DB::table('tenant_scheduling_configurations')->update(['owner_timezone' => \Illuminate\Support\Facades\DB::raw('default_timezone')]);
        \Illuminate\Support\Facades\DB::table('scheduling_requests')->update(['owner_timezone' => \Illuminate\Support\Facades\DB::raw('timezone')]);
    }

    public function down(): void
    {
        Schema::table('scheduling_requests', function (Blueprint $table): void { $table->dropColumn('owner_timezone'); });
        Schema::table('tenant_scheduling_configurations', function (Blueprint $table): void { $table->dropColumn('owner_timezone'); });
    }
};
