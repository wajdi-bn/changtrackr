<?php

namespace App\Policies;

use App\Models\Intervention;
use App\Models\User;

class InterventionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('interventions.view');
    }

    public function view(User $user, Intervention $intervention): bool
    {
        if (! $user->can('interventions.view') || ! $this->belongsToUserScope($user, $intervention)) {
            return false;
        }

        return ! $user->hasRole('technician') || $intervention->assigned_technician_id === $user->id;
    }

    public function update(User $user, Intervention $intervention): bool
    {
        if (! $this->belongsToUserScope($user, $intervention)) {
            return false;
        }

        return $user->can('interventions.manage') || (
            $user->can('interventions.report') && $intervention->assigned_technician_id === $user->id
        );
    }

    private function belongsToUserScope(User $user, Intervention $intervention): bool
    {
        return $user->canAccessOrganization($intervention->organization_id);
    }
}
