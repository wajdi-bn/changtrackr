<?php

namespace App\Jobs;

use App\Services\Maintenance\MaintenancePlanService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMaintenanceOccurrences implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public readonly ?int $maintenancePlanId = null) {}

    public function handle(MaintenancePlanService $plans): void
    {
        $plans->generateUpcoming($this->maintenancePlanId);
    }

    public function uniqueId(): string
    {
        return $this->maintenancePlanId === null ? 'all' : (string) $this->maintenancePlanId;
    }
}
