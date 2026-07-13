<?php

namespace App\Policies;

use App\Models\Tariff;
use App\Models\User;

class TariffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tariffs.view');
    }

    public function view(User $user, Tariff $tariff): bool
    {
        return $user->can('tariffs.view')
            && ($user->hasRole('super_admin') || $tariff->organization_id === $user->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('tariffs.manage')
            && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function update(User $user, Tariff $tariff): bool
    {
        return $user->can('tariffs.manage')
            && ($user->hasRole('super_admin') || $tariff->organization_id === $user->organization_id);
    }

    public function delete(User $user, Tariff $tariff): bool
    {
        return $this->update($user, $tariff);
    }

    public function assign(User $user, Tariff $tariff): bool
    {
        return $this->update($user, $tariff);
    }
}
