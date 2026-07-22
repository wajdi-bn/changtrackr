<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Intervention;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class OperationalDocumentController extends Controller
{
    public function alert(Alert $alert): Response
    {
        Gate::authorize('view', $alert);
        $alert->load(['organization', 'station', 'connector', 'assignedTechnician', 'events.actor', 'intervention']);
        $document = [
            'eyebrow' => 'Alert report',
            'title' => $alert->reference.' - '.$alert->title,
            'status' => $alert->status,
            'summary' => $alert->description,
            'facts' => [
                'Severity' => ucfirst($alert->severity),
                'Problem type' => $alert->problem_type,
                'Station' => $alert->station?->name,
                'Connector' => $alert->connector?->external_id ?? 'Station-wide',
                'Assigned technician' => $alert->assignedTechnician?->name ?? 'Unassigned',
                'Detected at' => $this->date($alert->detected_at),
                'SLA due at' => $this->date($alert->due_at),
                'Resolved at' => $this->date($alert->resolved_at),
            ],
            'sections' => array_filter([
                ['title' => 'Suggested cause', 'body' => $alert->suggested_cause],
                ['title' => 'Recommended action', 'body' => $alert->recommended_action],
                ['title' => 'OCPP evidence', 'body' => $alert->ocpp_log],
            ], fn (array $section) => filled($section['body'])),
            'timeline' => $alert->events->map(fn ($event) => [
                'date' => $this->date($event->occurred_at),
                'title' => str_replace('_', ' ', ucfirst($event->event_type)),
                'description' => $event->description,
                'actor' => $event->actor?->name ?? 'System',
            ])->all(),
        ];

        return $this->entity($document, $alert->organization?->name, "alert-{$alert->reference}.pdf");
    }

    public function intervention(Intervention $intervention): Response
    {
        Gate::authorize('view', $intervention);
        $intervention->load([
            'organization', 'station', 'connector', 'assignedTechnician', 'createdBy', 'alert',
            'maintenancePlan', 'events.actor', 'report.submittedBy', 'photos.uploadedBy',
        ]);
        $report = $intervention->report;
        $document = [
            'eyebrow' => $intervention->maintenance_plan_id ? 'Maintenance execution report' : 'Intervention report',
            'title' => $intervention->reference.' - '.$intervention->station?->name,
            'status' => $intervention->status,
            'summary' => $intervention->problem,
            'facts' => [
                'Priority' => ucfirst($intervention->priority),
                'Assigned technician' => $intervention->assignedTechnician?->name ?? 'Unassigned',
                'Station' => $intervention->station?->name,
                'Connector' => $intervention->connector?->external_id ?? 'Station-wide',
                'Scheduled at' => $this->date($intervention->scheduled_at),
                'Started at' => $this->date($intervention->started_at),
                'Completed at' => $this->date($intervention->ended_at),
                'Actual duration' => $report?->actual_duration_minutes ? $report->actual_duration_minutes.' minutes' : 'Not submitted',
            ],
            'sections' => array_values(array_filter([
                ['title' => 'Diagnosis', 'body' => $report?->diagnosis ?? $intervention->diagnosis],
                ['title' => 'Actions taken', 'body' => $report?->actions_taken ?? $intervention->resolution],
                ['title' => 'Final outcome', 'body' => $report?->final_outcome ?? $intervention->final_status],
                ['title' => 'Parts used', 'body' => implode(', ', $report?->parts ?? $intervention->parts ?? [])],
                ['title' => 'Observations', 'body' => $report?->observations ?? $intervention->comments],
                ['title' => 'Safety checks', 'body' => $report ? collect($report->safety_checks)->map(fn ($checked, $label) => str_replace('_', ' ', ucfirst($label)).': '.($checked ? 'Passed' : 'Not confirmed'))->implode(' | ') : null],
            ], fn (array $section) => filled($section['body']))),
            'timeline' => $intervention->events->map(fn ($event) => [
                'date' => $this->date($event->occurred_at),
                'title' => str_replace('_', ' ', ucfirst($event->event_type)),
                'description' => $event->description,
                'actor' => $event->actor?->name ?? 'System',
            ])->all(),
            'photos' => $intervention->photos->map(fn ($photo) => [
                'phase' => ucfirst($photo->phase),
                'caption' => $photo->caption ?: $photo->original_name,
                'data' => $this->photoData($photo->disk, $photo->path, $photo->mime_type),
            ])->filter(fn (array $photo) => $photo['data'] !== null)->all(),
        ];

        return $this->entity($document, $intervention->organization?->name, "intervention-{$intervention->reference}.pdf");
    }

    public function maintenance(Intervention $maintenance): Response
    {
        abort_if($maintenance->maintenance_plan_id === null, 404);

        return $this->intervention($maintenance);
    }

    public function receipt(Payment $payment): Response
    {
        Gate::authorize('view', $payment);
        $payment->load(['organization', 'user', 'chargingSession.station', 'chargingSession.connector', 'chargingSession.tariff', 'chargingSession.chargingPlan']);
        $session = $payment->chargingSession;

        return Pdf::loadView('reports.payment-receipt', [
            'payment' => $payment,
            'session' => $session,
            'issuedAt' => now()->format('d M Y, H:i'),
        ])->setPaper('a4')->download("receipt-{$payment->reference}.pdf");
    }

    /** @param array<string, mixed> $document */
    private function entity(array $document, ?string $organization, string $filename): Response
    {
        return Pdf::loadView('reports.entity', [
            'document' => $document,
            'organization' => $organization ?? 'ChargeTrackr platform',
            'generatedAt' => now()->format('d M Y, H:i'),
        ])->setPaper('a4')->download($filename);
    }

    private function photoData(string $disk, string $path, string $mimeType): ?string
    {
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return 'data:'.$mimeType.';base64,'.base64_encode(Storage::disk($disk)->get($path));
    }

    private function date(mixed $value): string
    {
        return $value?->format('d M Y, H:i') ?? '-';
    }
}
