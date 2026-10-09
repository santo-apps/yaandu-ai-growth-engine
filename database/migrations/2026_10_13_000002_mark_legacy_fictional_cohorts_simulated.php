<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('pilot_cohorts')->where(function ($query): void {
            $query->whereRaw('lower(name) like ?', ['%simulat%'])->orWhereRaw('lower(name) like ?', ['%fictional%']);
        })->update(['data_classification' => 'simulated']);
    }

    public function down(): void
    {
        // Classification is intentionally not reversed; cohort provenance must remain explicit.
    }
};
