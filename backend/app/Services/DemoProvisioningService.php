<?php

namespace App\Services;

use App\Models\AccountInvitation;
use App\Models\DemoRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DemoProvisioningService
{
    public function __construct(
        private readonly AccountInvitationService $invitations,
        private readonly OrganizationBillingService $billing,
    ) {}

    /** @return array{demo_request: DemoRequest, invitation: AccountInvitation, user: User, token: string} */
    public function provision(
        DemoRequest $demoRequest,
        User $actor,
        string $organizationName,
        string $administratorName,
        int $trialDays,
    ): array {
        if ($demoRequest->status !== 'under_review' || $demoRequest->organization_id !== null) {
            throw ValidationException::withMessages([
                'status' => ['Only a request under review can create an organization.'],
            ]);
        }

        return DB::transaction(function () use ($demoRequest, $actor, $organizationName, $administratorName, $trialDays): array {
            $lockedRequest = DemoRequest::query()->lockForUpdate()->findOrFail($demoRequest->id);
            if ($lockedRequest->status !== 'under_review' || $lockedRequest->organization_id !== null) {
                throw ValidationException::withMessages([
                    'status' => ['This demo request has already been processed.'],
                ]);
            }

            $organization = Organization::query()->create([
                'name' => $organizationName,
                'slug' => $this->uniqueSlug($organizationName),
                'contact_email' => $lockedRequest->email,
                'contact_phone' => $lockedRequest->phone,
                'status' => 'active',
                'settings' => [
                    'source' => 'demo_request',
                ],
            ]);

            $this->billing->createTrial($organization, $actor, $trialDays);

            $invitationResult = $this->invitations->invite(
                $organization,
                $actor,
                $administratorName,
                $lockedRequest->email,
                'admin',
                $lockedRequest,
            );

            $lockedRequest->update([
                'status' => 'provisioned',
                'organization_id' => $organization->id,
                'handled_by_id' => $actor->id,
                'decided_at' => now(),
                'provisioned_at' => now(),
            ]);

            return [
                'demo_request' => $lockedRequest->fresh(['handledBy', 'organization', 'invitations']),
                ...$invitationResult,
            ];
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
