<?php

namespace App\Policies;

use App\Models\ChargingPlan;
use App\Models\User;

class ChargingPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tariffs.view');
    }

    public function view(User $user, ChargingPlan $chargingPlan): bool
    {
        return $user->can('tariffs.view')
            && ($user->hasRole('super_admin') || $chargingPlan->organization_id === $user->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('tariffs.manage')
            && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function update(User $user, ChargingPlan $chargingPlan): bool
    {
        return $user->can('tariffs.manage')
            && ($user->hasRole('super_admin') || $chargingPlan->organization_id === $user->organization_id);
    }

    public function delete(User $user, ChargingPlan $chargingPlan): bool
    {
        return $this->update($user, $chargingPlan);
    }
}
