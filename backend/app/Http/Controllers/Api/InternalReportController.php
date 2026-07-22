<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InternalReportResource;
use App\Models\InternalReport;
use App\Models\User;
use App\Services\Notifications\OperationalNotificationService;
use App\Services\PlatformAuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class InternalReportController extends Controller
{
    private const RELATIONS = ['organization', 'sender.roles', 'recipient.roles'];

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $filters = $request->validate([
            'mailbox' => ['required', Rule::in(['inbox', 'sent', 'drafts', 'archived'])],
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in($this->categories())],
        ]);
        $mailbox = $filters['mailbox'];
        $query = InternalReport::query()->where('organization_id', $user->organization_id);
        $this->applyMailbox($query, $user, $mailbox);
        $reports = $query->with(self::RELATIONS)
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhere('summary', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")))
            ->latest($mailbox === 'inbox' ? 'sent_at' : 'updated_at')
            ->get();

        return response()->json([
            'data' => InternalReportResource::collection($reports),
            'summary' => $this->mailboxSummary($user),
        ]);
    }

    public function recipients(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $users = User::query()->where('organization_id', $user->organization_id)
            ->where('status', 'active')->where('id', '!=', $user->id)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', User::ORGANIZATION_ROLES))
            ->with('roles:id,name')->orderBy('name')->get(['id', 'name', 'email', 'avatar_url']);

        return response()->json(['data' => $users->map(fn (User $recipient) => [
            'id' => $recipient->id, 'name' => $recipient->name, 'email' => $recipient->email,
            'avatar_url' => $recipient->avatar_url, 'role' => $recipient->primaryRoleName(),
        ])->values()]);
    }

    public function store(Request $request, OperationalNotificationService $notifications, PlatformAuditService $audit): JsonResponse
    {
        $user = $this->actor($request);
        $attributes = $this->validatedPayload($request);
        $sendNow = (bool) ($attributes['send_now'] ?? false);
        unset($attributes['send_now']);
        if ($sendNow && empty($attributes['recipient_id'])) {
            throw ValidationException::withMessages(['recipient_id' => ['Choose a recipient before sending the report.']]);
        }
        $this->assertRecipient($user, $attributes['recipient_id'] ?? null);

        $report = InternalReport::query()->create([
            ...$attributes,
            'organization_id' => $user->organization_id,
            'sender_id' => $user->id,
            'status' => $sendNow ? 'sent' : 'draft',
            'sent_at' => $sendNow ? now() : null,
        ]);
        $audit->record($user, $sendNow ? 'report.sent' : 'report.drafted', $report, ($sendNow ? 'Sent' : 'Created').' internal report '.$report->title.'.');
        if ($sendNow) {
            $this->notifyRecipient($report, $notifications);
        }

        return (new InternalReportResource($report->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(Request $request, InternalReport $internalReport, PlatformAuditService $audit): InternalReportResource
    {
        $user = $this->actor($request);
        $this->assertDraftOwner($internalReport, $user);
        $attributes = $this->validatedPayload($request, true);
        unset($attributes['send_now']);
        $this->assertRecipient($user, $attributes['recipient_id'] ?? $internalReport->recipient_id);
        $internalReport->update($attributes);
        $audit->record($user, 'report.updated', $internalReport, 'Updated draft report '.$internalReport->title.'.');

        return new InternalReportResource($internalReport->fresh()->load(self::RELATIONS));
    }

    public function send(Request $request, InternalReport $internalReport, OperationalNotificationService $notifications, PlatformAuditService $audit): InternalReportResource
    {
        $user = $this->actor($request);
        $this->assertDraftOwner($internalReport, $user);
        $validated = $request->validate(['recipient_id' => ['sometimes', 'integer', 'exists:users,id']]);
        $recipientId = array_key_exists('recipient_id', $validated) ? (int) $validated['recipient_id'] : $internalReport->recipient_id;
        $this->assertRecipient($user, $recipientId);
        abort_if($recipientId === null, 422, 'A recipient is required.');
        DB::transaction(fn () => $internalReport->update(['recipient_id' => $recipientId, 'status' => 'sent', 'sent_at' => now()]));
        $audit->record($user, 'report.sent', $internalReport, 'Sent internal report '.$internalReport->title.'.');
        $this->notifyRecipient($internalReport->fresh(), $notifications);

        return new InternalReportResource($internalReport->fresh()->load(self::RELATIONS));
    }

    public function read(Request $request, InternalReport $internalReport): InternalReportResource
    {
        $user = $this->actor($request);
        abort_unless($internalReport->organization_id === $user->organization_id && $internalReport->recipient_id === $user->id && $internalReport->status !== 'draft', 403);
        if ($internalReport->read_at === null) {
            $internalReport->update(['status' => 'read', 'read_at' => now()]);
        }

        return new InternalReportResource($internalReport->fresh()->load(self::RELATIONS));
    }

    public function archive(Request $request, InternalReport $internalReport): JsonResponse
    {
        $user = $this->actor($request);
        abort_unless($internalReport->organization_id === $user->organization_id && in_array($user->id, [$internalReport->sender_id, $internalReport->recipient_id], true), 403);
        $field = $internalReport->sender_id === $user->id ? 'sender_archived_at' : 'recipient_archived_at';
        $internalReport->update([$field => now()]);

        return response()->json(['message' => 'Report archived.']);
    }

    public function destroy(Request $request, InternalReport $internalReport): Response
    {
        $user = $this->actor($request);
        $this->assertDraftOwner($internalReport, $user);
        $internalReport->delete();

        return response()->noContent();
    }

    public function document(Request $request, InternalReport $internalReport): Response
    {
        $user = $this->actor($request);
        abort_unless($internalReport->organization_id === $user->organization_id && in_array($user->id, [$internalReport->sender_id, $internalReport->recipient_id], true), 403);
        $internalReport->load(self::RELATIONS);

        return Pdf::loadView('reports.internal-report', ['report' => $internalReport])
            ->setPaper('a4')->download('internal-report-'.$internalReport->id.'.pdf');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->organization_id !== null && $user->can('reports.exchange') && $user->hasAnyRole(User::ORGANIZATION_ROLES), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => [$required, 'string', 'min:3', 'max:180'],
            'category' => [$required, Rule::in($this->categories())],
            'priority' => [$required, Rule::in(['normal', 'important', 'urgent'])],
            'summary' => ['nullable', 'string', 'max:800'],
            'body' => [$required, 'string', 'min:10', 'max:20000'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'related_type' => ['nullable', Rule::in(['alert', 'intervention', 'maintenance', 'station', 'operations'])],
            'related_id' => ['nullable', 'integer', 'min:1', 'required_with:related_type'],
            'send_now' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertDraftOwner(InternalReport $report, User $user): void
    {
        abort_unless($report->organization_id === $user->organization_id && $report->sender_id === $user->id && $report->status === 'draft', 403);
    }

    private function assertRecipient(User $sender, ?int $recipientId): void
    {
        if ($recipientId === null) {
            return;
        }
        $valid = User::query()->whereKey($recipientId)->where('organization_id', $sender->organization_id)
            ->where('status', 'active')->where('id', '!=', $sender->id)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', User::ORGANIZATION_ROLES))->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['recipient_id' => ['The recipient must be an active employee of your organization.']]);
        }
    }

    private function applyMailbox(Builder $query, User $user, string $mailbox): void
    {
        match ($mailbox) {
            'inbox' => $query->where('recipient_id', $user->id)->whereNotNull('sent_at')->whereNull('recipient_archived_at'),
            'sent' => $query->where('sender_id', $user->id)->whereNotNull('sent_at')->whereNull('sender_archived_at'),
            'drafts' => $query->where('sender_id', $user->id)->where('status', 'draft'),
            'archived' => $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('sender_id', $user->id)->whereNotNull('sender_archived_at'))
                ->orWhere(fn (Builder $query) => $query->where('recipient_id', $user->id)->whereNotNull('recipient_archived_at'))),
        };
    }

    /** @return array<string, int> */
    private function mailboxSummary(User $user): array
    {
        $scope = InternalReport::query()->where('organization_id', $user->organization_id);

        return [
            'inbox' => (clone $scope)->where('recipient_id', $user->id)->whereNotNull('sent_at')->whereNull('recipient_archived_at')->count(),
            'unread' => (clone $scope)->where('recipient_id', $user->id)->whereNotNull('sent_at')->whereNull('read_at')->whereNull('recipient_archived_at')->count(),
            'sent' => (clone $scope)->where('sender_id', $user->id)->whereNotNull('sent_at')->whereNull('sender_archived_at')->count(),
            'drafts' => (clone $scope)->where('sender_id', $user->id)->where('status', 'draft')->count(),
        ];
    }

    private function notifyRecipient(InternalReport $report, OperationalNotificationService $notifications): void
    {
        $recipient = User::query()->find($report->recipient_id);
        if ($recipient === null) {
            return;
        }
        $notifications->notifyUser($recipient, [
            'category' => 'report', 'severity' => $report->priority === 'urgent' ? 'warning' : 'info',
            'title' => 'New report from '.$report->sender()->value('name'),
            'message' => $report->title,
            'action_url' => $recipient->hasRole('admin') ? '/analytics-reports?mailbox=inbox' : ($recipient->hasRole('technician') ? '/maintenance-reports?mailbox=inbox' : '/reports?mailbox=inbox'),
            'entity_type' => InternalReport::class, 'entity_id' => $report->id,
            'deduplication_key' => 'internal-report:'.$report->id.':sent',
        ], ['in_app']);
    }

    /** @return array<int, string> */
    private function categories(): array
    {
        return ['operations', 'incident', 'intervention', 'maintenance', 'performance', 'handover'];
    }
}
