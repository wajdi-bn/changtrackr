<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;

class StationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stations.view');
    }

    public function view(User $user, Station $station): bool
    {
        return $user->can('stations.view') && $this->belongsToUserScope($user, $station);
    }

    public function create(User $user): bool
    {
        return $user->can('stations.create') && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function update(User $user, Station $station): bool
    {
        return $user->can('stations.update') && $this->belongsToUserScope($user, $station);
    }

    public function delete(User $user, Station $station): bool
    {
        return $user->can('stations.delete') && $this->belongsToUserScope($user, $station);
    }

    public function viewCommands(User $user, Station $station): bool
    {
        return $user->can('ocpp_commands.view') && $this->belongsToUserScope($user, $station);
    }

    public function executeCommands(User $user, Station $station): bool
    {
        return $user->can('ocpp_commands.execute') && $this->belongsToUserScope($user, $station);
    }

    public function viewSimulation(User $user, Station $station): bool
    {
        return $user->can('ocpp_simulation.view') && $this->belongsToUserScope($user, $station);
    }

    public function diagnoseSimulation(User $user, Station $station): bool
    {
        return $user->can('ocpp_simulation.diagnose') && $this->belongsToUserScope($user, $station);
    }

    public function controlSimulation(User $user, Station $station): bool
    {
        return $user->can('ocpp_simulation.control') && $this->belongsToUserScope($user, $station);
    }

    private function belongsToUserScope(User $user, Station $station): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasRole('client')) {
            return $station->organization()->where('status', 'active')->exists();
        }

        return $user->canAccessOrganization($station->organization_id);
    }
}
