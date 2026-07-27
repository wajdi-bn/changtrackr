<?php

use App\Jobs\GenerateMaintenanceOccurrences;
use App\Services\Notifications\NotificationSlaService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:check-sla', function (NotificationSlaService $service): void {
    $result = $service->scan();
    $this->info("SLA scan completed: {$result['approaching']} approaching, {$result['overdue']} overdue, {$result['maintenance_due']} maintenance due.");
})->purpose('Create idempotent SLA and maintenance reminders');

Schedule::command('availability:refresh')
    ->everyThirtySeconds()
    ->withoutOverlapping(2);

Schedule::command('ocpp:expire-commands')
    ->everyTenSeconds()
    ->withoutOverlapping(1);

Schedule::job(new GenerateMaintenanceOccurrences)
    ->hourly()
    ->withoutOverlapping(5);

Schedule::command('notifications:check-sla')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('audit:prune')
    ->dailyAt('02:30')
    ->withoutOverlapping(10);

Schedule::command('commercial:sync')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('client-subscriptions:sync')
    ->hourly()
    ->withoutOverlapping(10);
