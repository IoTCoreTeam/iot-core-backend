<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:prune', function () {
    $cutoff = now()->subDays(7);
    $deleted = DatabaseNotification::where('created_at', '<', $cutoff)->delete();
    $this->info("Pruned {$deleted} notifications older than {$cutoff}.");
})->purpose('Delete notifications older than 7 days');

Schedule::command('notifications:prune')->daily();
