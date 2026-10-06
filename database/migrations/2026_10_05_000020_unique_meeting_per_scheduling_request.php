<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('meeting_bookings')->where('status', 'booked')->update(['status' => 'SCHEDULED']);
        DB::table('meeting_bookings')->where('status', 'cancelled')->update(['status' => 'CANCELLED']);
        Schema::table('meeting_bookings', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'scheduling_request_id'], 'meeting_request_once_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_bookings', function (Blueprint $table): void {
            $table->dropUnique('meeting_request_once_unique');
        });
    }
};
