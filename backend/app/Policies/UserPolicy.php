<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $managedUser): bool
    {
        return $user->can('users.view') && $this->sameScope($user, $managedUser);
    }

    public function create(User $user): bool
    {
        return $user->can('users.create')
            && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function update(User $user, User $managedUser): bool
    {
        return $user->can('users.update') && $this->sameScope($user, $managedUser);
    }

    public function delete(User $user, User $managedUser): bool
    {
        return $user->can('users.delete') && $this->sameScope($user, $managedUser);
    }

    private function sameScope(User $user, User $managedUser): bool
    {
        return $user->hasRole('super_admin')
            || ($user->organization_id !== null && $user->organization_id === $managedUser->organization_id);
    }
}
