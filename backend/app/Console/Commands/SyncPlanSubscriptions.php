<?php

namespace App\Console\Commands;

use App\Services\PlanSubscriptionService;
use Illuminate\Console\Command;

class SyncPlanSubscriptions extends Command
{
    protected $signature = 'client-subscriptions:sync';

    protected $description = 'Renew or expire client charging-plan subscriptions';

    public function handle(PlanSubscriptionService $service): int
    {
        $result = $service->scan();
        $this->info("Client subscription sync completed: {$result['renewed']} renewed, {$result['past_due']} past due, {$result['expired']} expired.");

        return self::SUCCESS;
    }
}
