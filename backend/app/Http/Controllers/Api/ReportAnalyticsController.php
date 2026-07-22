<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\ChargingSession;
use App\Models\InternalReport;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use App\Services\Reports\ReportExportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReportAnalyticsController extends Controller
{
    public function export(Request $request, string $scope, ReportExportService $exports): Response
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(['csv', 'json', 'pdf'])],
            'period' => ['nullable', Rule::in(['7d', '30d', '90d'])],
        ]);
        $response = match ($scope) {
            'platform' => $this->platform($request),
            'organization' => $this->organization($request),
            'operations' => $this->operations($request),
            'field' => $this->field($request),
            default => abort(404),
        };
        $payload = $response->getData(true)['data'];
        $period = $payload['period'];
        $rows = $this->flattenSnapshot($payload);
        $title = match ($scope) {
            'platform' => 'Platform governance report',
            'organization' => 'Organization performance report',
            'operations' => 'Network operations report',
            'field' => 'Field service report',
        };

        return $exports->dataset(
            $validated['format'],
            $scope.'-report-'.$period['to'],
            $title,
            'Verified reporting snapshot for '.$period['label'].'.',
            ['section' => 'Section', 'metric' => 'Metric', 'value' => 'Value'],
            $rows,
            $request->user(),
            ['period' => $period['label'], 'from' => $period['from'], 'to' => $period['to']],
        );
    }

    public function platform(Request $request): JsonResponse
    {
        $user = $this->role($request, 'super_admin');
        [$from, $to, $period] = $this->period($request);
        $sessions = ChargingSession::query()->whereBetween('started_at', [$from, $to]);
        $payments = Payment::query()->where('status', 'paid')->whereBetween('paid_at', [$from, $to]);
        $organizations = Organization::query();

        return response()->json(['data' => [
            'role' => 'super_admin', 'period' => $period,
            'kpis' => [
                'organizations' => (clone $organizations)->count(),
                'active_organizations' => (clone $organizations)->where('status', 'active')->count(),
                'platform_users' => User::query()->whereHas('roles', fn (Builder $query) => $query->whereIn('name', User::EMPLOYEE_ROLES))->count(),
                'managed_stations' => Station::query()->count(),
                'sessions' => (clone $sessions)->count(),
                'revenue_millimes' => (int) (clone $payments)->sum('amount_millimes'),
            ],
            'trend' => $this->sessionTrend($sessions, $from, $to),
            'organization_status' => $this->distribution(Organization::query(), 'status'),
            'user_roles' => collect(User::EMPLOYEE_ROLES)->map(fn (string $role) => [
                'key' => $role, 'label' => str_replace('_', ' ', ucfirst($role)), 'value' => User::role($role)->count(),
            ])->values(),
            'organization_ranking' => Organization::query()->withCount(['stations', 'users'])
                ->withSum(['payments as paid_revenue_millimes' => fn (Builder $query) => $query->where('status', 'paid')->whereBetween('paid_at', [$from, $to])], 'amount_millimes')
                ->withCount(['chargingSessions as period_sessions_count' => fn (Builder $query) => $query->whereBetween('started_at', [$from, $to])])
                ->orderByDesc('paid_revenue_millimes')->limit(8)->get()->map(fn (Organization $organization) => [
                    'id' => $organization->id, 'name' => $organization->name, 'status' => $organization->status,
                    'stations' => $organization->stations_count, 'users' => $organization->users_count,
                    'sessions' => $organization->period_sessions_count, 'revenue_millimes' => (int) ($organization->paid_revenue_millimes ?? 0),
                ]),
            'risk' => [
                'inactive_organizations' => (clone $organizations)->where('status', '!=', 'active')->count(),
                'offline_stations' => Station::query()->where('status', 'offline')->count(),
                'critical_alerts' => Alert::query()->where('severity', 'critical')->where('status', '!=', 'resolved')->count(),
                'failed_payments' => Payment::query()->where('status', 'failed')->whereBetween('created_at', [$from, $to])->count(),
            ],
            'generated_by' => $user->name,
        ]]);
    }

    public function organization(Request $request): JsonResponse
    {
        $user = $this->role($request, 'admin');
        [$from, $to, $period] = $this->period($request);
        $organizationId = (int) $user->organization_id;
        $sessions = ChargingSession::query()->where('organization_id', $organizationId)->whereBetween('started_at', [$from, $to]);
        $payments = Payment::query()->where('organization_id', $organizationId)->where('status', 'paid')->whereBetween('paid_at', [$from, $to]);
        $stations = Station::query()->where('organization_id', $organizationId);
        $alerts = Alert::query()->where('organization_id', $organizationId)->whereBetween('detected_at', [$from, $to]);

        return response()->json(['data' => [
            'role' => 'admin', 'period' => $period,
            'business' => [
                'revenue_millimes' => (int) (clone $payments)->sum('amount_millimes'),
                'sessions' => (clone $sessions)->count(),
                'energy_kwh' => round((float) (clone $sessions)->sum('energy_kwh'), 3),
                'customers' => (clone $sessions)->whereNotNull('client_id')->distinct()->count('client_id'),
            ],
            'workforce' => [
                'employees' => User::query()->where('organization_id', $organizationId)->count(),
                'operators' => User::role('operator')->where('organization_id', $organizationId)->count(),
                'technicians' => User::role('technician')->where('organization_id', $organizationId)->count(),
                'open_work' => Intervention::query()->where('organization_id', $organizationId)->whereNotIn('status', ['resolved', 'cancelled'])->count(),
            ],
            'network' => [
                'stations' => (clone $stations)->count(),
                'availability_percent' => round((float) ((clone $stations)->avg('uptime_percent') ?? 0), 2),
                'open_alerts' => Alert::query()->where('organization_id', $organizationId)->where('status', '!=', 'resolved')->count(),
                'sla_breaches' => Alert::query()->where('organization_id', $organizationId)->where('status', '!=', 'resolved')->where('due_at', '<', now())->count(),
            ],
            'trend' => $this->sessionTrend($sessions, $from, $to),
            'alert_distribution' => $this->distribution($alerts, 'severity'),
            'station_performance' => (clone $stations)->withCount(['chargingSessions as period_sessions' => fn (Builder $query) => $query->whereBetween('started_at', [$from, $to])])
                ->withSum(['chargingSessions as period_energy' => fn (Builder $query) => $query->whereBetween('started_at', [$from, $to])], 'energy_kwh')
                ->orderByDesc('period_sessions')->limit(8)->get()->map(fn (Station $station) => [
                    'id' => $station->id, 'name' => $station->name, 'city' => $station->city, 'status' => $station->status,
                    'uptime_percent' => $station->uptime_percent, 'sessions' => $station->period_sessions,
                    'energy_kwh' => round((float) ($station->period_energy ?? 0), 3), 'open_alerts' => $station->open_alerts_count,
                ]),
            'report_activity' => $this->reportActivity($user),
        ]]);
    }

    public function operations(Request $request): JsonResponse
    {
        $user = $this->role($request, 'operator');
        [$from, $to, $period] = $this->period($request);
        $organizationId = (int) $user->organization_id;
        $stations = Station::query()->where('organization_id', $organizationId);
        $sessions = ChargingSession::query()->where('organization_id', $organizationId)->whereBetween('started_at', [$from, $to]);
        $alerts = Alert::query()->where('organization_id', $organizationId)->whereBetween('detected_at', [$from, $to]);

        return response()->json(['data' => [
            'role' => 'operator', 'period' => $period,
            'live' => [
                'available' => (clone $stations)->where('status', 'available')->count(),
                'charging' => (clone $stations)->where('status', 'charging')->count(),
                'offline' => (clone $stations)->where('status', 'offline')->count(),
                'maintenance' => (clone $stations)->where('status', 'maintenance')->count(),
                'active_sessions' => ChargingSession::query()->where('organization_id', $organizationId)->whereIn('status', ['pending', 'charging', 'stopping'])->count(),
                'unresolved_alerts' => Alert::query()->where('organization_id', $organizationId)->where('status', '!=', 'resolved')->count(),
            ],
            'trend' => $this->operationsTrend($sessions, $alerts, $from, $to),
            'station_status' => $this->distribution($stations, 'status'),
            'alert_severity' => $this->distribution($alerts, 'severity'),
            'station_watchlist' => (clone $stations)->orderByDesc('open_alerts_count')->orderBy('uptime_percent')->limit(10)->get()
                ->map(fn (Station $station) => ['id' => $station->id, 'name' => $station->name, 'city' => $station->city, 'status' => $station->status, 'uptime_percent' => $station->uptime_percent, 'utilization_percent' => $station->utilization_percent, 'open_alerts' => $station->open_alerts_count, 'last_heartbeat_at' => $station->last_heartbeat_at?->toISOString()]),
            'handover' => [
                'in_progress_interventions' => Intervention::query()->where('organization_id', $organizationId)->whereIn('status', ['in-progress', 'paused', 'waiting-parts'])->count(),
                'maintenance_due' => Intervention::query()->where('organization_id', $organizationId)->whereNotNull('maintenance_plan_id')->where('status', 'assigned')->whereBetween('scheduled_at', [now(), now()->addDays(7)])->count(),
                ...$this->reportActivity($user),
            ],
        ]]);
    }

    public function field(Request $request): JsonResponse
    {
        $user = $this->role($request, 'technician');
        [$from, $to, $period] = $this->period($request);
        $work = Intervention::query()->where('assigned_technician_id', $user->id);
        $periodWork = (clone $work)->whereBetween('created_at', [$from, $to]);
        $completed = (clone $work)->where('status', 'resolved')->whereBetween('ended_at', [$from, $to]);

        return response()->json(['data' => [
            'role' => 'technician', 'period' => $period,
            'workload' => [
                'assigned' => (clone $work)->where('status', 'assigned')->count(),
                'in_progress' => (clone $work)->whereIn('status', ['in-progress', 'paused', 'waiting-parts'])->count(),
                'completed' => (clone $completed)->count(),
                'overdue' => (clone $work)->whereNotIn('status', ['resolved', 'cancelled'])->where('scheduled_at', '<', now())->count(),
                'average_minutes' => round((float) ((clone $completed)->whereNotNull('started_at')->whereNotNull('ended_at')->get()->avg(fn (Intervention $item) => $item->started_at->diffInMinutes($item->ended_at)) ?? 0), 1),
            ],
            'completion_trend' => $this->completionTrend($completed, $from, $to),
            'outcomes' => $this->distribution((clone $completed)->whereHas('report'), 'final_status'),
            'assignments' => (clone $work)->with(['station', 'maintenancePlan'])->whereNotIn('status', ['resolved', 'cancelled'])
                ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')->orderBy('scheduled_at')->limit(8)->get()->map(fn (Intervention $item) => [
                    'id' => $item->id, 'reference' => $item->reference, 'station' => $item->station?->name,
                    'type' => $item->maintenance_plan_id ? 'maintenance' : 'intervention', 'priority' => $item->priority,
                    'status' => $item->status, 'scheduled_at' => $item->scheduled_at?->toISOString(), 'problem' => $item->problem,
                ]),
            'report_activity' => [
                ...$this->reportActivity($user),
                'field_reports_submitted' => (clone $periodWork)->whereHas('report')->count(),
            ],
        ]]);
    }

    private function role(Request $request, string $role): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole($role) && $user->can('reports.view'), 403);

        return $user;
    }

    /** @return array{CarbonImmutable, CarbonImmutable, array<string, mixed>} */
    private function period(Request $request): array
    {
        $key = $request->validate(['period' => ['nullable', 'in:7d,30d,90d']])['period'] ?? '30d';
        $days = (int) rtrim($key, 'd');
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subDays($days - 1)->startOfDay();

        return [$from, $to, ['key' => $key, 'label' => "Last {$days} days", 'from' => $from->toDateString(), 'to' => $to->toDateString()]];
    }

    private function sessionTrend(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $values = (clone $query)->selectRaw('DATE(started_at) as day, COUNT(*) as sessions, COALESCE(SUM(energy_kwh), 0) as energy_kwh, COALESCE(SUM(CASE WHEN payment_status = ? THEN total_millimes ELSE 0 END), 0) as revenue_millimes', ['paid'])
            ->groupByRaw('DATE(started_at)')->orderBy('day')->get()->keyBy('day');

        return $this->days($from, $to)->map(fn (string $day) => [
            'date' => $day, 'sessions' => (int) ($values[$day]?->sessions ?? 0),
            'energy_kwh' => round((float) ($values[$day]?->energy_kwh ?? 0), 3),
            'revenue_millimes' => (int) ($values[$day]?->revenue_millimes ?? 0),
        ])->all();
    }

    private function operationsTrend(Builder $sessions, Builder $alerts, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = collect($this->sessionTrend($sessions, $from, $to))->keyBy('date');
        $alertValues = (clone $alerts)->selectRaw('DATE(detected_at) as day, COUNT(*) as alerts')->groupByRaw('DATE(detected_at)')->get()->keyBy('day');

        return $this->days($from, $to)->map(fn (string $day) => [...$base[$day], 'alerts' => (int) ($alertValues[$day]?->alerts ?? 0)])->all();
    }

    private function completionTrend(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $values = (clone $query)->selectRaw('DATE(ended_at) as day, COUNT(*) as completed')->groupByRaw('DATE(ended_at)')->get()->keyBy('day');

        return $this->days($from, $to)->map(fn (string $day) => ['date' => $day, 'completed' => (int) ($values[$day]?->completed ?? 0)])->all();
    }

    private function distribution(Builder $query, string $column): array
    {
        return (clone $query)->selectRaw("{$column} as key, COUNT(*) as value")->groupBy($column)->orderByDesc('value')->get()
            ->map(fn ($item) => ['key' => $item->key ?? 'unknown', 'label' => str_replace('-', ' ', ucfirst($item->key ?? 'Unknown')), 'value' => (int) $item->value])->all();
    }

    /** @return array<string, int> */
    private function reportActivity(User $user): array
    {
        $scope = InternalReport::query()->where('organization_id', $user->organization_id);

        return [
            'unread_reports' => (clone $scope)->where('recipient_id', $user->id)->whereNotNull('sent_at')->whereNull('read_at')->count(),
            'reports_received' => (clone $scope)->where('recipient_id', $user->id)->whereNotNull('sent_at')->count(),
            'reports_sent' => (clone $scope)->where('sender_id', $user->id)->whereNotNull('sent_at')->count(),
            'draft_reports' => (clone $scope)->where('sender_id', $user->id)->where('status', 'draft')->count(),
        ];
    }

    private function days(CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Support\Collection
    {
        return collect(CarbonPeriod::create($from, $to))->map(fn ($date) => $date->format('Y-m-d'));
    }

    /** @param array<string, mixed> $payload */
    private function flattenSnapshot(array $payload): array
    {
        return collect($payload)->except(['role', 'period', 'generated_by'])->flatMap(function (mixed $value, string $section): array {
            if (! is_array($value)) {
                return [['section' => $this->humanize($section), 'metric' => 'Value', 'value' => $value]];
            }

            if (array_is_list($value)) {
                return collect($value)->map(fn (mixed $item, int $index) => [
                    'section' => $this->humanize($section),
                    'metric' => is_array($item) ? ($item['name'] ?? $item['label'] ?? $item['date'] ?? '#'.($index + 1)) : '#'.($index + 1),
                    'value' => is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $item,
                ])->all();
            }

            return collect($value)->map(fn (mixed $item, string $metric) => [
                'section' => $this->humanize($section),
                'metric' => $this->humanize($metric),
                'value' => is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $item,
            ])->values()->all();
        })->values()->all();
    }

    private function humanize(string $value): string
    {
        return str_replace('_', ' ', ucfirst($value));
    }
}
