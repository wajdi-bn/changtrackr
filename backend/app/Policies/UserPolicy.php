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
        return $user->can('users.view')
            && $this->isEmployee($managedUser)
            && $this->sameScope($user, $managedUser);
    }

    public function create(User $user): bool
    {
        return $user->can('users.create')
            && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function viewCustomers(User $user): bool
    {
        return $user->can('customers.view')
            && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function viewCustomer(User $user, User $customer): bool
    {
        if (! $this->viewCustomers($user) || ! $customer->hasRole('client')) {
            return false;
        }

        return $user->hasRole('super_admin')
            || $customer->chargingSessions()
                ->where('organization_id', $user->organization_id)
                ->exists();
    }

    public function exportCustomers(User $user): bool
    {
        return $user->can('customers.export')
            && ($user->hasRole('super_admin') || $user->organization_id !== null);
    }

    public function update(User $user, User $managedUser): bool
    {
        if (! $user->can('users.update') || ! $this->isEmployee($managedUser) || ! $this->sameScope($user, $managedUser)) {
            return false;
        }

        return $user->hasRole('super_admin') || $managedUser->hasAnyRole(['operator', 'technician']);
    }

    public function delete(User $user, User $managedUser): bool
    {
        if (! $user->can('users.delete') || ! $this->isEmployee($managedUser) || ! $this->sameScope($user, $managedUser)) {
            return false;
        }

        return $user->hasRole('super_admin') || $managedUser->hasAnyRole(['operator', 'technician']);
    }

    private function sameScope(User $user, User $managedUser): bool
    {
        return $user->canAccessOrganization($managedUser->organization_id);
    }

    private function isEmployee(User $user): bool
    {
        return $user->hasAnyRole(User::EMPLOYEE_ROLES);
    }
}
