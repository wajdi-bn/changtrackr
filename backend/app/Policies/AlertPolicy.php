<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('alerts.view');
    }

    public function view(User $user, Alert $alert): bool
    {
        if (! $user->can('alerts.view') || ! $this->belongsToUserScope($user, $alert)) {
            return false;
        }

        return ! $user->hasRole('technician') || $alert->assigned_technician_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('alerts.manage') && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function update(User $user, Alert $alert): bool
    {
        return $user->can('alerts.manage') && $this->belongsToUserScope($user, $alert);
    }

    public function assign(User $user, Alert $alert): bool
    {
        return $alert->status !== 'resolved'
            && $user->can('alerts.assign')
            && $this->belongsToUserScope($user, $alert);
    }

    public function createIntervention(User $user, Alert $alert): bool
    {
        return $alert->status !== 'resolved'
            && $user->can('interventions.manage')
            && $this->belongsToUserScope($user, $alert);
    }

    private function belongsToUserScope(User $user, Alert $alert): bool
    {
        return $user->canAccessOrganization($alert->organization_id);
    }
}
