<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DemoRequests\ProvisionDemoRequestRequest;
use App\Http\Requests\DemoRequests\RejectDemoRequestRequest;
use App\Http\Requests\DemoRequests\StoreDemoRequestRequest;
use App\Http\Requests\DemoRequests\UpdateDemoRequestRequest;
use App\Http\Resources\DemoRequestResource;
use App\Models\DemoRequest;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Notifications\DemoRequestReceivedNotification;
use App\Notifications\NewDemoRequestNotification;
use App\Services\AccountInvitationService;
use App\Services\DemoProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DemoRequestController extends Controller
{
    private const RELATIONS = ['handledBy', 'organization', 'invitations'];

    public function store(StoreDemoRequestRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $duplicateExists = DemoRequest::query()
            ->where('email', $attributes['email'])
            ->whereNotIn('status', ['rejected', 'provisioned'])
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'email' => ['A recent open demo request already exists for this email address.'],
            ]);
        }

        unset($attributes['consent_accepted'], $attributes['website']);
        $demoRequest = DemoRequest::query()->create([
            ...$attributes,
            'reference' => $this->uniqueReference(),
            'status' => 'submitted',
            'consent_at' => now(),
            'submitted_ip_hash' => $request->ip()
                ? hash_hmac('sha256', $request->ip(), (string) config('app.key'))
                : null,
        ]);

        Notification::route('mail', $demoRequest->email)
            ->notify(new DemoRequestReceivedNotification($demoRequest));

        $notificationEmail = config('demo.notification_email');
        if (is_string($notificationEmail) && $notificationEmail !== '') {
            Notification::route('mail', $notificationEmail)
                ->notify(new NewDemoRequestNotification($demoRequest));
        }

        return response()->json([
            'message' => 'Your demo request has been recorded. Our platform team will review it shortly.',
            'reference' => $demoRequest->reference,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DemoRequest::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(DemoRequest::STATUSES)],
            'objective' => ['nullable', Rule::in(DemoRequest::OBJECTIVES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $baseQuery = DemoRequest::query();
        $requests = $this->applyFilters(DemoRequest::query(), $filters)
            ->with(self::RELATIONS)
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => DemoRequestResource::collection($requests->items()),
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                'submitted' => (clone $baseQuery)->where('status', 'submitted')->count(),
                'under_review' => (clone $baseQuery)->where('status', 'under_review')->count(),
                'provisioned' => (clone $baseQuery)->where('status', 'provisioned')->count(),
                'rejected' => (clone $baseQuery)->where('status', 'rejected')->count(),
            ],
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function show(DemoRequest $demoRequest): DemoRequestResource
    {
        Gate::authorize('view', $demoRequest);

        return new DemoRequestResource($demoRequest->load(self::RELATIONS));
    }

    public function update(UpdateDemoRequestRequest $request, DemoRequest $demoRequest): DemoRequestResource
    {
        Gate::authorize('update', $demoRequest);
        $demoRequest->update($request->validated());

        return new DemoRequestResource($demoRequest->fresh(self::RELATIONS));
    }

    public function startReview(Request $request, DemoRequest $demoRequest): DemoRequestResource
    {
        Gate::authorize('update', $demoRequest);
        /** @var User $actor */
        $actor = $request->user();
        $demoRequest = $this->transition($demoRequest, ['submitted'], [
            'status' => 'under_review',
            'handled_by_id' => $actor->id,
            'review_started_at' => now(),
        ], 'Only a submitted request can enter review.');

        return new DemoRequestResource($demoRequest);
    }

    public function reject(RejectDemoRequestRequest $request, DemoRequest $demoRequest): DemoRequestResource
    {
        Gate::authorize('update', $demoRequest);
        /** @var User $actor */
        $actor = $request->user();
        $attributes = $request->validated();
        $demoRequest = $this->transition($demoRequest, ['submitted', 'under_review'], [
            ...$attributes,
            'status' => 'rejected',
            'handled_by_id' => $actor->id,
            'decided_at' => now(),
        ], 'Only a submitted or reviewed request can be rejected.');

        return new DemoRequestResource($demoRequest);
    }

    public function reopen(Request $request, DemoRequest $demoRequest): DemoRequestResource
    {
        Gate::authorize('update', $demoRequest);
        /** @var User $actor */
        $actor = $request->user();
        $demoRequest = $this->transition($demoRequest, ['rejected'], [
            'status' => 'under_review',
            'handled_by_id' => $actor->id,
            'review_started_at' => now(),
            'decided_at' => null,
            'rejection_reason' => null,
        ], 'Only a rejected request can be reopened.');

        return new DemoRequestResource($demoRequest);
    }

    public function provision(
        ProvisionDemoRequestRequest $request,
        DemoRequest $demoRequest,
        DemoProvisioningService $provisioning,
    ): DemoRequestResource {
        Gate::authorize('provision', $demoRequest);
        /** @var User $actor */
        $actor = $request->user();
        $attributes = $request->validated();
        $result = $provisioning->provision(
            $demoRequest,
            $actor,
            $attributes['organization_name'],
            $attributes['admin_name'],
            $attributes['trial_days'],
        );

        $result['user']->notify(new AccountInvitationNotification($result['invitation'], $result['token']));

        return new DemoRequestResource($result['demo_request']);
    }

    public function issueInvitation(
        Request $request,
        DemoRequest $demoRequest,
        AccountInvitationService $invitations,
    ): DemoRequestResource {
        Gate::authorize('provision', $demoRequest);
        /** @var User $actor */
        $actor = $request->user();
        $result = $invitations->reissueForDemo($demoRequest, $actor);
        $result['user']->notify(new AccountInvitationNotification($result['invitation'], $result['token']));

        return new DemoRequestResource($demoRequest->fresh(self::RELATIONS));
    }

    public function revokeInvitation(
        DemoRequest $demoRequest,
        AccountInvitationService $invitations,
    ): DemoRequestResource {
        Gate::authorize('provision', $demoRequest);
        $invitations->revokeForDemo($demoRequest);

        return new DemoRequestResource($demoRequest->fresh(self::RELATIONS));
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(reference) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(full_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(company_name) LIKE ?', [$needle]));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['objective'] ?? null, fn (Builder $query, string $objective) => $query->whereJsonContains('objectives', $objective));
    }

    private function uniqueReference(): string
    {
        do {
            $reference = 'DEMO-'.now()->format('Ym').'-'.Str::upper(Str::random(8));
        } while (DemoRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /** @param list<string> $allowedStatuses */
    private function transition(DemoRequest $demoRequest, array $allowedStatuses, array $attributes, string $error): DemoRequest
    {
        $updated = DemoRequest::query()
            ->whereKey($demoRequest->id)
            ->whereIn('status', $allowedStatuses)
            ->update($attributes);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['status' => [$error]]);
        }

        return DemoRequest::query()->with(self::RELATIONS)->findOrFail($demoRequest->id);
    }
}
