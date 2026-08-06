<?php

namespace App\Observers;

use App\Models\AvailabilityTransition;
use App\Services\Dashboard\AvailabilityMetricsCache;

class AvailabilityTransitionObserver
{
    public function __construct(private readonly AvailabilityMetricsCache $cache) {}

    public function created(AvailabilityTransition $transition): void
    {
        if ($transition->connector_id !== null) {
            return;
        }

        $this->cache->invalidateOrganization($transition->organization_id);
    }
}
