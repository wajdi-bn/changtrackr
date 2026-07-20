<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Services\AccountInvitationService;
use Illuminate\Support\Facades\Gate;

class EmployeeInvitationController extends Controller
{
    public function remind(User $user, AccountInvitationService $invitations): UserResource
    {
        Gate::authorize('update', $user);
        /** @var User $actor */
        $actor = request()->user();
        $result = $invitations->remindEmployee($user, $actor);
        $result['user']->notify(new AccountInvitationNotification($result['invitation'], $result['token']));

        return new UserResource($this->loadUser($result['user']));
    }

    public function renew(User $user, AccountInvitationService $invitations): UserResource
    {
        Gate::authorize('update', $user);
        /** @var User $actor */
        $actor = request()->user();
        $result = $invitations->renewEmployee($user, $actor);
        $result['user']->notify(new AccountInvitationNotification($result['invitation'], $result['token']));

        return new UserResource($this->loadUser($result['user']));
    }

    public function cancel(User $user, AccountInvitationService $invitations): UserResource
    {
        Gate::authorize('update', $user);
        /** @var User $actor */
        $actor = request()->user();
        $invitations->cancelEmployee($user, $actor);

        return new UserResource($this->loadUser($user));
    }

    private function loadUser(User $user): User
    {
        return $user->load(['organization', 'roles.permissions', 'permissions', 'latestAccountInvitation'])
            ->loadCount(['assignedAlerts', 'assignedInterventions', 'chargingSessions', 'payments']);
    }
}
