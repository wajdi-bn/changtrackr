<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DemoRequests\ProvisionDemoRequestRequest;
use App\Http\Requests\DemoRequests\StoreDemoRequestRequest;
use App\Http\Requests\DemoRequests\UpdateDemoRequestRequest;
use App\Http\Resources\DemoRequestResource;
use App\Models\DemoRequest;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
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
            'status' => 'new',
            'consent_at' => now(),
            'submitted_ip_hash' => $request->ip()
                ? hash_hmac('sha256', $request->ip(), (string) config('app.key'))
                : null,
        ]);

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
            'topic' => ['nullable', Rule::in(DemoRequest::TOPICS)],
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
                'new' => (clone $baseQuery)->where('status', 'new')->count(),
                'in_progress' => (clone $baseQuery)->whereIn('status', ['under_review', 'contacted', 'demo_scheduled', 'qualified', 'approved'])->count(),
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
        $attributes = $request->validated();
        $nextStatus = $attributes['status'] ?? $demoRequest->status;

        if ($nextStatus !== $demoRequest->status && ! in_array($nextStatus, $demoRequest->allowedTransitions(), true)) {
            throw ValidationException::withMessages([
                'status' => ['This status transition is not allowed.'],
            ]);
        }

        if ($demoRequest->status === 'provisioned') {
            throw ValidationException::withMessages([
                'status' => ['A provisioned request is read-only.'],
            ]);
        }

        /** @var User $actor */
        $actor = $request->user();
        $demoRequest->update([
            ...$attributes,
            'handled_by_id' => $nextStatus === 'new' ? null : $actor->id,
        ]);

        return new DemoRequestResource($demoRequest->fresh(self::RELATIONS));
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

    public function resendInvitation(
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
            ->when($filters['topic'] ?? null, fn (Builder $query, string $topic) => $query->where('topic', $topic));
    }

    private function uniqueReference(): string
    {
        do {
            $reference = 'DEMO-'.now()->format('Ym').'-'.Str::upper(Str::random(8));
        } while (DemoRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
