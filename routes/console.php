<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('growth:status', function () { $this->info('Yaandu AI Growth Engine foundation is installed.'); })->purpose('Show platform foundation status');

Artisan::command('proposals:expire', function (): void {
    $count = DB::table('proposals')->whereIn('status', ['sent', 'viewed'])->whereDate('valid_until', '<', today())
        ->update(['status' => 'expired', 'updated_at' => now()]);
    $this->info("Expired {$count} proposal(s).");
})->purpose('Expire proposals that passed their validity date');

Schedule::command('proposals:expire')->dailyAt('00:20');
