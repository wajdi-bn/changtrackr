<?php

namespace App\Console\Commands;

use App\Services\OrganizationSubscriptionLifecycleService;
use Illuminate\Console\Command;

class SyncOrganizationSubscriptions extends Command
{
    protected $signature = 'commercial:sync';

    protected $description = 'Advance organization trials, billing periods, grace periods and suspensions';

    public function handle(OrganizationSubscriptionLifecycleService $service): int
    {
        $result = $service->scan();
        $this->info("Commercial sync completed: {$result['reminders']} reminders, {$result['grace']} grace transitions, {$result['suspended']} suspensions, {$result['overdue_invoices']} overdue invoices.");

        return self::SUCCESS;
    }
}
