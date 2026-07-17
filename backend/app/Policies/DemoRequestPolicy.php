<?php

namespace App\Policies;

use App\Models\DemoRequest;
use App\Models\User;

class DemoRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin') && $user->can('demo_requests.view');
    }

    public function view(User $user, DemoRequest $demoRequest): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DemoRequest $demoRequest): bool
    {
        return $user->hasRole('super_admin') && $user->can('demo_requests.manage');
    }

    public function provision(User $user, DemoRequest $demoRequest): bool
    {
        return $this->update($user, $demoRequest);
    }
}
