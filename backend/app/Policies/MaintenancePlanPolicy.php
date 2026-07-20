<?php

namespace App\Policies;

use App\Models\MaintenancePlan;
use App\Models\User;

class MaintenancePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('maintenances.view');
    }

    public function view(User $user, MaintenancePlan $maintenancePlan): bool
    {
        return $user->can('maintenances.view')
            && $user->canAccessOrganization($maintenancePlan->organization_id)
            && (! $user->hasRole('technician') || $maintenancePlan->assigned_technician_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('maintenances.manage')
            && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function update(User $user, MaintenancePlan $maintenancePlan): bool
    {
        return $user->can('maintenances.manage')
            && $user->canAccessOrganization($maintenancePlan->organization_id);
    }
}
