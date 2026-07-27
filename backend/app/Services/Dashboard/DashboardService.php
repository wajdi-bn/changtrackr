<?php

namespace App\Services\Dashboard;

use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\ChargingSession;
use App\Models\DemoRequest;
use App\Models\Intervention;
use App\Models\InterventionEvent;
use App\Models\OcppIdTag;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlanSubscription;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(private readonly AvailabilityMetrics $availabilityMetrics) {}

    /** @return array<string, mixed> */
    public function forUser(User $user, string $periodKey): array
    {
        $role = $user->primaryRoleName();
        abort_unless($role !== null, 403, 'A single valid role is required.');
        $period = $this->period($periodKey);

        return match ($role) {
            'super_admin' => $this->superAdmin($period),
            'admin' => $this->organization($user, $period, true),
            'operator' => $this->organization($user, $period, false),
            'technician' => $this->technician($user, $period),
            'client' => $this->client($user, $period),
            default => abort(403, 'This role does not have a dashboard.'),
        };
    }

    /** @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    private function superAdmin(array $period): array
    {
        $sessions = $this->sessionsInPeriod(ChargingSession::query(), $period);
        $payments = $this->paymentsInPeriod(Payment::query()->where('status', 'paid'), $period);
        $alerts = $this->alertsInPeriod(Alert::query(), $period);
        $previousRevenue = $this->sumPayments(Payment::query()->where('status', 'paid'), $period, true);
        $previousSessions = $this->countSessions(ChargingSession::query(), $period, true);
        $stations = Station::query()->get();
        $availability = $this->availabilityMetrics->calculate($stations, $period['start'], $period['end']);

        return $this->payload(
            role: 'super_admin',
            period: $period,
            headline: 'Platform control center',
            description: 'Global tenant adoption, network health and settled business activity.',
            kpis: [
                $this->kpi('organizations', 'Active organizations', Organization::query()->where('status', 'active')->count(), 'number'),
                $this->kpi('users', 'Active platform users', User::query()->where('status', 'active')->count(), 'number'),
                $this->kpi('availability', 'Tracked availability', $availability['availability_percent'], 'percentage', null, $period['label']),
                $this->kpi('revenue', 'Settled revenue', round($payments->sum('amount_millimes') / 1000, 3), 'currency', $this->change($payments->sum('amount_millimes'), $previousRevenue), $period['label']),
            ],
            trend: $this->withAvailability($this->trend($period, $sessions, $payments, $alerts, collect(), [
                ['key' => 'sessions', 'label' => 'Sessions'],
                ['key' => 'availability_percent', 'label' => 'Availability (%)'],
            ], 'Platform activity'), $stations, $period),
            breakdowns: [
                $this->breakdown('organizations', 'Organizations by status', Organization::query()->select('status')->get()->countBy('status')),
                $this->breakdown('stations', 'Stations by calculated status', $stations->countBy('status')),
            ],
            rankings: [$this->organizationRanking($period)],
            activities: $this->platformActivities(),
            methodology: [
                'Tracked availability = operational monitored time / total monitored time. Available and charging are operational states.',
                'Settled revenue includes only payments with the paid status and a paid timestamp inside the selected period.',
                'Organization ranking is ordered by settled revenue, with sessions and stations shown as context.',
                'Session comparison: '.$sessions->count().' in the selected period versus '.$previousSessions.' in the preceding period.',
            ],
            widgets: [
                'module_counts' => [
                    'organizations' => Organization::query()->count(),
                    'demo_requests' => DemoRequest::query()->whereIn('status', ['submitted', 'under_review'])->count(),
                    'users' => User::query()->count(),
                    'stations' => Station::query()->count(),
                    'alerts' => Alert::query()->where('status', '!=', 'resolved')->count(),
                    'sessions' => ChargingSession::query()->count(),
                    'payments' => Payment::query()->count(),
                ],
            ],
        );
    }

    /** @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    private function organization(User $user, array $period, bool $administrator): array
    {
        $organizationId = (int) $user->organization_id;
        $sessionsQuery = ChargingSession::query()->where('organization_id', $organizationId);
        $paymentsQuery = Payment::query()->where('organization_id', $organizationId)->where('status', 'paid');
        $alertsQuery = Alert::query()->where('organization_id', $organizationId);
        $interventionsQuery = Intervention::query()->where('organization_id', $organizationId);
        $sessions = $this->sessionsInPeriod((clone $sessionsQuery)->with(['station', 'client']), $period);
        $payments = $this->paymentsInPeriod((clone $paymentsQuery)->with('user'), $period);
        $alerts = $this->alertsInPeriod(clone $alertsQuery, $period);
        $completed = $this->interventionsInPeriod((clone $interventionsQuery)->with(['station', 'assignedTechnician']), $period);
        $stations = Station::query()->where('organization_id', $organizationId)->get();
        $availability = $this->availabilityMetrics->calculate($stations, $period['start'], $period['end']);

        if ($administrator) {
            $customers = ChargingSession::query()->where('organization_id', $organizationId)->whereNotNull('client_id')->distinct('client_id')->count('client_id');
            $employees = User::query()->where('organization_id', $organizationId)->where('status', 'active')->count();
            $previousRevenue = $this->sumPayments(clone $paymentsQuery, $period, true);
            $organizationUsers = User::query()->where('organization_id', $organizationId)->where('status', 'active')->with('roles')->get();
            $usersByRole = $organizationUsers->flatMap(fn (User $account) => $account->getRoleNames())->countBy();
            $allInterventions = (clone $interventionsQuery)->get();
            $openAlerts = (clone $alertsQuery)->where('status', '!=', 'resolved')->get();
            $criticalAlerts = $openAlerts->where('severity', 'critical')->count();
            $resolutionRate = $allInterventions->isEmpty() ? 100.0 : $this->percentage($allInterventions->where('status', 'resolved')->count(), $allInterventions->count());
            $sessionSuccess = $sessions->isEmpty() ? 100.0 : $this->percentage($sessions->where('status', 'completed')->count(), $sessions->count());
            $alertControl = max(0.0, round(100 - min(100, ($criticalAlerts * 20) + ($openAlerts->count() * 5)), 1));
            $healthFactors = [
                ['label' => 'Tracked availability', 'value' => $availability['availability_percent']],
                ['label' => 'Alert control', 'value' => $alertControl],
                ['label' => 'Intervention resolution', 'value' => $resolutionRate],
                ['label' => 'Successful sessions', 'value' => $sessionSuccess],
            ];
            $kpis = [
                $this->kpi('employees', 'Active employees', $employees, 'number'),
                $this->kpi('customers', 'Organization customers', $customers, 'number'),
                $this->kpi('availability', 'Tracked availability', $availability['availability_percent'], 'percentage', null, $period['label']),
                $this->kpi('revenue', 'Settled revenue', round($payments->sum('amount_millimes') / 1000, 3), 'currency', $this->change($payments->sum('amount_millimes'), $previousRevenue), $period['label']),
                $this->kpi('stations', 'Managed stations', $stations->count(), 'number'),
                $this->kpi('sessions', 'Charging sessions', $sessions->count(), 'number', null, $period['label']),
            ];
            $rankings = [
                $this->clientRanking($payments),
                $this->operatorRanking($organizationId, $period),
                $this->technicianRanking($completed),
                $this->stationRanking($sessions),
                $this->regionRanking($sessions, $stations),
            ];
            $headline = 'Organization performance';
            $description = 'Business, workforce and charging-network results for '.$user->organization?->name.'.';
            $breakdowns = [
                $this->breakdown('users_by_role', 'Users by role', $usersByRole),
                $this->breakdown('stations', 'Stations by calculated status', $stations->countBy('status')),
                $this->breakdown('alerts', 'Alerts detected in period', $alerts->countBy('severity')),
            ];
            $widgets = [
                'organization' => [
                    'id' => $user->organization?->id,
                    'name' => $user->organization?->name,
                    'status' => $user->organization?->status,
                    'contact_email' => $user->organization?->contact_email,
                    'logo_url' => $user->organization?->logo_url,
                    'employees' => $employees,
                    'stations' => $stations->count(),
                ],
                'health' => [
                    'score' => round(collect($healthFactors)->avg('value'), 1),
                    'factors' => $healthFactors,
                ],
            ];
            $trendSeries = [
                ['key' => 'revenue_tnd', 'label' => 'Revenue (TND)'],
                ['key' => 'energy_kwh', 'label' => 'Energy (kWh)'],
            ];
        } else {
            $activeSessions = (clone $sessionsQuery)->whereIn('status', ['charging', 'stopping'])->count();
            $openAlerts = (clone $alertsQuery)->where('status', '!=', 'resolved')->count();
            $kpis = [
                $this->kpi('availability', 'Tracked availability', $availability['availability_percent'], 'percentage', null, $period['label']),
                $this->kpi('unavailable_time', 'Unavailable time', $availability['unavailable_hours'], 'duration', null, $period['label']),
                $this->kpi('active_sessions', 'Active sessions', $activeSessions, 'number'),
                $this->kpi('period_sessions', 'Sessions in period', $sessions->count(), 'number', $this->change($sessions->count(), $this->countSessions(clone $sessionsQuery, $period, true)), $period['label']),
                $this->kpi('energy', 'Energy delivered', round($sessions->sum('energy_kwh'), 2), 'energy', null, $period['label']),
                $this->kpi('open_alerts', 'Open alerts', $openAlerts, 'number'),
            ];
            $rankings = [$this->stationRanking($sessions), $this->regionRanking($sessions, $stations)];
            $headline = 'Live network operations';
            $description = 'Availability, charging demand and incidents requiring operational attention.';
            $breakdowns = [
                $this->breakdown('stations', 'Stations by calculated status', $stations->countBy('status')),
                $this->breakdown('alerts', 'Alerts detected in period', $alerts->countBy('severity')),
            ];
            $widgets = [];
            $trendSeries = [
                ['key' => 'availability_percent', 'label' => 'Availability (%)'],
                ['key' => 'sessions', 'label' => 'Charging sessions'],
            ];
        }

        return $this->payload(
            role: $administrator ? 'admin' : 'operator',
            period: $period,
            headline: $headline,
            description: $description,
            kpis: $kpis,
            trend: $this->withAvailability($this->trend($period, $sessions, $payments, $alerts, $completed, $trendSeries, $administrator ? 'Revenue and energy trend' : 'Availability trend'), $stations, $period),
            breakdowns: $breakdowns,
            rankings: $rankings,
            activities: $this->organizationActivities($organizationId, false),
            methodology: [
                'Tracked availability = operational monitored time / total monitored time for the selected period. Available and charging are operational states.',
                'Unavailable time combines offline, unavailable, faulted, reserved and maintenance intervals recorded in availability history.',
                'Revenue includes only settled payments in the selected period; failed, pending and preauthorized payments are excluded.',
                'Top clients are ranked by settled payment amount. Technicians are ranked by resolved interventions.',
                'Operator ranking counts authored alert and intervention history events during the selected period.',
                'Region and station rankings use charging sessions started during the selected period.',
            ],
            widgets: $widgets,
        );
    }

    /** @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    private function technician(User $user, array $period): array
    {
        $alertsQuery = Alert::query()->where('assigned_technician_id', $user->id);
        $interventionsQuery = Intervention::query()->where('assigned_technician_id', $user->id);
        $alerts = $this->alertsInPeriod(clone $alertsQuery, $period);
        $completed = $this->interventionsInPeriod((clone $interventionsQuery)->with('station'), $period);
        $openAlerts = (clone $alertsQuery)->where('status', '!=', 'resolved')->count();
        $critical = (clone $alertsQuery)->where('status', '!=', 'resolved')->where('severity', 'critical')->count();
        $activeWork = (clone $interventionsQuery)->whereIn('status', ['assigned', 'in-progress', 'paused', 'waiting-parts'])->count();
        $work = (clone $interventionsQuery)->with(['station', 'alert', 'report'])->get();
        $assignedAlerts = (clone $alertsQuery)->with(['station', 'connector'])->get();
        $scheduledToday = $work->filter(fn (Intervention $intervention) => $intervention->scheduled_at?->isToday() && $intervention->status !== 'resolved')->count();
        $overdue = $work->filter(fn (Intervention $intervention) => $intervention->scheduled_at?->isPast() && ! in_array($intervention->status, ['resolved', 'cancelled'], true))->count();
        $tasks = $work->reject(fn (Intervention $intervention) => in_array($intervention->status, ['resolved', 'cancelled'], true))
            ->sortBy(fn (Intervention $intervention) => $intervention->scheduled_at?->getTimestamp() ?? PHP_INT_MAX)
            ->take(5)
            ->values()
            ->map(fn (Intervention $intervention) => [
                'id' => $intervention->id,
                'reference' => $intervention->reference,
                'label' => str($intervention->problem)->limit(70)->toString(),
                'station' => $intervention->station?->name ?? 'Station',
                'scheduled_at' => $intervention->scheduled_at?->toIso8601String(),
                'scheduled_label' => $intervention->scheduled_at?->format('H:i') ?? 'Unscheduled',
                'status' => $intervention->status,
                'priority' => $intervention->priority,
                'action_url' => '/my-interventions?intervention='.$intervention->id,
            ])->all();
        $criticalAlertRows = $assignedAlerts->where('severity', 'critical')->where('status', '!=', 'resolved')->take(4)->values()->map(fn (Alert $alert) => [
            'id' => $alert->id,
            'title' => $alert->title,
            'station' => $alert->station?->name ?? $alert->reference,
            'connector' => $alert->connector?->external_id,
            'status' => $alert->status,
            'due_at' => $alert->due_at?->toIso8601String(),
            'due_label' => $alert->due_at?->diffForHumans(),
            'action_url' => '/assigned-alerts?alert='.$alert->id,
        ])->all();
        $responseMinutes = $work->filter(fn (Intervention $intervention) => $intervention->started_at && $intervention->alert?->detected_at)
            ->map(fn (Intervention $intervention) => $intervention->alert->detected_at->diffInMinutes($intervention->started_at));
        $slaCandidates = $work->filter(fn (Intervention $intervention) => $intervention->ended_at && $intervention->alert?->due_at);
        $slaRate = $slaCandidates->isEmpty() ? 100.0 : $this->percentage($slaCandidates->filter(fn (Intervention $intervention) => $intervention->ended_at->lte($intervention->alert->due_at))->count(), $slaCandidates->count());
        $technicianTrend = $this->withResolutionTime($this->trend($period, collect(), collect(), $alerts, $completed, [
            ['key' => 'completed', 'label' => 'Resolved work'],
            ['key' => 'alerts', 'label' => 'Assigned alerts'],
        ], 'My workload trend'), $completed);

        return $this->payload(
            role: 'technician',
            period: $period,
            headline: 'My field workload',
            description: 'Assigned incidents, maintenance deadlines and completed field work.',
            kpis: [
                $this->kpi('assigned_alerts', 'Open assigned alerts', $openAlerts, 'number'),
                $this->kpi('critical_tasks', 'Critical alerts', $critical, 'number'),
                $this->kpi('active_work', 'Active work orders', $activeWork, 'number'),
                $this->kpi('resolved', 'Resolved in period', $completed->count(), 'number', $this->change($completed->count(), $this->countInterventions(clone $interventionsQuery, $period, true)), $period['label']),
                $this->kpi('scheduled_today', 'Scheduled today', $scheduledToday, 'number'),
                $this->kpi('overdue_tasks', 'Overdue tasks', $overdue, 'number'),
            ],
            trend: $technicianTrend,
            breakdowns: [
                $this->breakdown('work', 'Assigned work by status', $work->countBy('status')),
                $this->breakdown('severity', 'Alerts by severity', $assignedAlerts->countBy('severity')),
                $this->breakdown('priority', 'Workload by priority', $work->countBy('priority')),
            ],
            rankings: [$this->technicianStationRanking($completed)],
            activities: $this->technicianActivities($user->id),
            methodology: [
                'Only alerts and interventions assigned to the authenticated technician are included.',
                'Resolved work is counted by intervention end time inside the selected period.',
                'Station ranking counts resolved interventions and does not include work assigned to another technician.',
            ],
            widgets: [
                'tasks' => $tasks,
                'critical_alerts' => $criticalAlertRows,
                'recent_faults' => $assignedAlerts->sortByDesc('detected_at')->take(3)->values()->map(fn (Alert $alert) => [
                    'id' => $alert->id,
                    'station' => $alert->station?->name ?? $alert->reference,
                    'issue' => $alert->title,
                    'severity' => $alert->severity,
                    'occurred_relative' => $alert->detected_at->diffForHumans(),
                    'action_url' => '/assigned-alerts?alert='.$alert->id,
                ])->all(),
                'performance' => [
                    ['label' => 'First response', 'value' => $responseMinutes->isEmpty() ? null : round($responseMinutes->avg()), 'unit' => 'min', 'helper' => 'Average detected-to-start time'],
                    ['label' => 'Resolution SLA', 'value' => $slaRate, 'unit' => '%', 'helper' => 'Resolved before alert due date'],
                    ['label' => 'Reports submitted', 'value' => $work->filter(fn (Intervention $intervention) => $intervention->report !== null)->count(), 'unit' => '', 'helper' => 'Completed intervention reports'],
                ],
            ],
        );
    }

    /** @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    private function client(User $user, array $period): array
    {
        $sessionsQuery = ChargingSession::query()->where('client_id', $user->id);
        $paymentsQuery = Payment::query()->where('user_id', $user->id)->where('status', 'paid');
        $sessions = $this->sessionsInPeriod((clone $sessionsQuery)->with('station'), $period);
        $payments = $this->paymentsInPeriod(clone $paymentsQuery, $period);
        $activeSession = (clone $sessionsQuery)->whereIn('status', ['charging', 'stopping'])->count();
        $subscriptions = PlanSubscription::query()->where('user_id', $user->id)->current()->count();
        $activeSessionRecord = (clone $sessionsQuery)->with(['station', 'connector', 'chargingPlan', 'payment'])->whereIn('status', ['charging', 'stopping'])->latest('started_at')->first();
        $identifier = OcppIdTag::query()->where('user_id', $user->id)->where('status', 'active')->latest('last_used_at')->first();
        $currentSubscription = PlanSubscription::query()->with(['organization', 'chargingPlan'])->where('user_id', $user->id)->current()->latest('starts_at')->first();
        $allPayments = Payment::query()->where('user_id', $user->id)->get();
        $recentSessions = (clone $sessionsQuery)->with('station')->latest('started_at')->limit(5)->get();

        return $this->payload(
            role: 'client',
            period: $period,
            headline: 'My charging activity',
            description: 'Personal sessions, energy consumption, spending and current memberships.',
            kpis: [
                $this->kpi('active_session', 'Active sessions', $activeSession, 'number'),
                $this->kpi('sessions', 'Sessions in period', $sessions->count(), 'number', $this->change($sessions->count(), $this->countSessions(clone $sessionsQuery, $period, true)), $period['label']),
                $this->kpi('energy', 'Energy delivered', round($sessions->sum('energy_kwh'), 2), 'energy', null, $period['label']),
                $this->kpi('spend', 'Paid in period', round($payments->sum('amount_millimes') / 1000, 3), 'currency', $this->change($payments->sum('amount_millimes'), $this->sumPayments(clone $paymentsQuery, $period, true)), $period['label']),
                $this->kpi('memberships', 'Active memberships', $subscriptions, 'number'),
                $this->kpi('identifiers', 'Active identifiers', OcppIdTag::query()->where('user_id', $user->id)->where('status', 'active')->count(), 'number'),
            ],
            trend: $this->trend($period, $sessions, $payments, collect(), collect(), [
                ['key' => 'energy_kwh', 'label' => 'Energy (kWh)'],
                ['key' => 'revenue_tnd', 'label' => 'Spend (TND)'],
            ], 'My consumption trend'),
            breakdowns: [
                $this->breakdown('sessions', 'My sessions by status', (clone $sessionsQuery)->get(['status'])->countBy('status')),
                $this->breakdown('memberships', 'Current memberships', collect(['active' => $subscriptions])),
                $this->breakdown('payments', 'Payment status', $allPayments->countBy('status')),
            ],
            rankings: [$this->clientStationRanking($sessions)],
            activities: $this->clientActivities($user->id),
            methodology: [
                'All values are restricted to the authenticated client, including sessions across different organizations.',
                'Energy is the final or latest projected energy recorded on sessions started during the selected period.',
                'Paid amount excludes pending, failed and released preauthorizations.',
            ],
            widgets: [
                'active_session' => $activeSessionRecord ? [
                    'id' => $activeSessionRecord->id,
                    'reference' => $activeSessionRecord->reference,
                    'station' => $activeSessionRecord->station?->name ?? $activeSessionRecord->station_name,
                    'connector' => $activeSessionRecord->connector?->external_id ?? $activeSessionRecord->connector_external_id,
                    'status' => $activeSessionRecord->status,
                    'started_at' => $activeSessionRecord->started_at->toIso8601String(),
                    'energy_kwh' => (float) $activeSessionRecord->energy_kwh,
                    'current_power_kw' => $activeSessionRecord->current_power_kw,
                    'state_of_charge_percent' => $activeSessionRecord->state_of_charge_percent,
                    'total_millimes' => $activeSessionRecord->total_millimes,
                    'action_url' => '/my-sessions?session='.$activeSessionRecord->id,
                ] : null,
                'identifier' => $identifier ? [
                    'label' => $identifier->label,
                    'masked_token' => $identifier->masked_token,
                    'status' => $identifier->status,
                    'last_used_at' => $identifier->last_used_at?->toIso8601String(),
                ] : null,
                'subscription' => $currentSubscription ? [
                    'plan' => $currentSubscription->chargingPlan?->name,
                    'organization' => $currentSubscription->organization?->name,
                    'discount_basis_points' => $currentSubscription->discount_basis_points,
                    'current_period_ends_at' => $currentSubscription->current_period_ends_at->toIso8601String(),
                ] : null,
                'recent_sessions' => $recentSessions->map(fn (ChargingSession $session) => [
                    'id' => $session->id,
                    'reference' => $session->reference,
                    'station' => $session->station?->name ?? $session->station_name,
                    'energy_kwh' => (float) $session->energy_kwh,
                    'amount_millimes' => $session->total_millimes,
                    'status' => $session->status,
                    'payment_status' => $session->payment_status,
                    'started_at' => $session->started_at->toIso8601String(),
                    'action_url' => '/my-sessions?session='.$session->id,
                ])->all(),
            ],
        );
    }

    /** @param array<string, mixed> $period
     * @param  list<array{key:string,label:string}>  $series
     * @return array<string, mixed>
     */
    private function trend(array $period, Collection $sessions, Collection $payments, Collection $alerts, Collection $interventions, array $series, string $title): array
    {
        $sessionsByDay = $sessions->groupBy(fn (ChargingSession $session) => $session->started_at->toDateString());
        $paymentsByDay = $payments->groupBy(fn (Payment $payment) => ($payment->paid_at ?? $payment->created_at)->toDateString());
        $alertsByDay = $alerts->groupBy(fn (Alert $alert) => $alert->detected_at->toDateString());
        $interventionsByDay = $interventions->groupBy(fn (Intervention $intervention) => ($intervention->ended_at ?? $intervention->updated_at)->toDateString());
        $points = [];
        $cursor = $period['start']->copy();
        while ($cursor->lte($period['end'])) {
            $key = $cursor->toDateString();
            $daySessions = $sessionsByDay->get($key, collect());
            $dayPayments = $paymentsByDay->get($key, collect());
            $points[] = [
                'date' => $key,
                'label' => $cursor->format('M d'),
                'sessions' => $daySessions->count(),
                'energy_kwh' => round($daySessions->sum('energy_kwh'), 2),
                'revenue_tnd' => round($dayPayments->sum('amount_millimes') / 1000, 3),
                'alerts' => $alertsByDay->get($key, collect())->count(),
                'completed' => $interventionsByDay->get($key, collect())->count(),
            ];
            $cursor->addDay();
        }

        return ['title' => $title, 'series' => $series, 'points' => $points];
    }

    /**
     * @param  array<string, mixed>  $trend
     * @param  Collection<int, Station>  $stations
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function withAvailability(array $trend, Collection $stations, array $period): array
    {
        $availability = collect($this->availabilityMetrics->daily($stations, $period['start'], $period['end']))->keyBy('date');
        $trend['points'] = collect($trend['points'])->map(function (array $point) use ($availability): array {
            $point['availability_percent'] = $availability->get($point['date'], ['availability_percent' => 0])['availability_percent'];

            return $point;
        })->all();

        return $trend;
    }

    /**
     * @param  array<string, mixed>  $trend
     * @param  Collection<int, Intervention>  $completed
     * @return array<string, mixed>
     */
    private function withResolutionTime(array $trend, Collection $completed): array
    {
        $byDay = $completed->filter(fn (Intervention $intervention) => $intervention->started_at && $intervention->ended_at)
            ->groupBy(fn (Intervention $intervention) => $intervention->ended_at->toDateString());
        $trend['points'] = collect($trend['points'])->map(function (array $point) use ($byDay): array {
            $durations = $byDay->get($point['date'], collect())->map(
                fn (Intervention $intervention) => $intervention->started_at->diffInMinutes($intervention->ended_at) / 60,
            );
            $point['resolution_hours'] = $durations->isEmpty() ? 0 : round($durations->avg(), 1);

            return $point;
        })->all();

        return $trend;
    }

    /** @param array<string, mixed> $period */
    private function sessionsInPeriod(Builder $query, array $period): Collection
    {
        return $query->whereBetween('started_at', [$period['start'], $period['end']])->get();
    }

    /** @param array<string, mixed> $period */
    private function paymentsInPeriod(Builder $query, array $period): Collection
    {
        return $query->whereBetween('paid_at', [$period['start'], $period['end']])->get();
    }

    /** @param array<string, mixed> $period */
    private function alertsInPeriod(Builder $query, array $period): Collection
    {
        return $query->whereBetween('detected_at', [$period['start'], $period['end']])->get();
    }

    /** @param array<string, mixed> $period */
    private function interventionsInPeriod(Builder $query, array $period): Collection
    {
        return $query->where('status', 'resolved')->whereBetween('ended_at', [$period['start'], $period['end']])->get();
    }

    /** @param array<string, mixed> $period */
    private function countSessions(Builder $query, array $period, bool $previous): int
    {
        [$start, $end] = $this->bounds($period, $previous);

        return $query->whereBetween('started_at', [$start, $end])->count();
    }

    /** @param array<string, mixed> $period */
    private function sumPayments(Builder $query, array $period, bool $previous): int
    {
        [$start, $end] = $this->bounds($period, $previous);

        return (int) $query->whereBetween('paid_at', [$start, $end])->sum('amount_millimes');
    }

    /** @param array<string, mixed> $period */
    private function countInterventions(Builder $query, array $period, bool $previous): int
    {
        [$start, $end] = $this->bounds($period, $previous);

        return $query->where('status', 'resolved')->whereBetween('ended_at', [$start, $end])->count();
    }

    /** @param array<string, mixed> $period
     * @return array{Carbon, Carbon}
     */
    private function bounds(array $period, bool $previous): array
    {
        return $previous
            ? [$period['previous_start'], $period['previous_end']]
            : [$period['start'], $period['end']];
    }

    /** @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    private function organizationRanking(array $period): array
    {
        $organizations = Organization::query()->get()->keyBy('id');
        $payments = $this->paymentsInPeriod(Payment::query()->where('status', 'paid'), $period)->groupBy('organization_id');
        $sessions = $this->sessionsInPeriod(ChargingSession::query(), $period)->groupBy('organization_id');
        $stationCounts = Station::query()->get(['organization_id'])->countBy('organization_id');
        $items = $organizations->map(function (Organization $organization) use ($payments, $sessions, $stationCounts): array {
            return $this->rankingItem(
                $organization->id,
                $organization->name,
                ($sessions->get($organization->id, collect())->count()).' sessions · '.($stationCounts->get($organization->id, 0)).' stations',
                round($payments->get($organization->id, collect())->sum('amount_millimes') / 1000, 3),
                'TND',
                '/organizations?organization='.$organization->id,
            );
        })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('organizations', 'Top organizations', 'Settled revenue during the selected period.', $items);
    }

    private function clientRanking(Collection $payments): array
    {
        $items = $payments->whereNotNull('user_id')->groupBy('user_id')->map(function (Collection $group): array {
            $payment = $group->first();

            return $this->rankingItem($payment->user_id, $payment->user?->name ?? 'Unknown client', $group->count().' settled payments', round($group->sum('amount_millimes') / 1000, 3), 'TND', '/users/customers?customer='.$payment->user_id);
        })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('clients', 'Top clients', 'Ranked by settled payments during the selected period.', $items);
    }

    /** @param array<string, mixed> $period */
    private function operatorRanking(int $organizationId, array $period): array
    {
        $alertEvents = AlertEvent::query()->with(['actor.roles'])
            ->whereBetween('occurred_at', [$period['start'], $period['end']])
            ->whereHas('alert', fn (Builder $query) => $query->where('organization_id', $organizationId))->get();
        $interventionEvents = InterventionEvent::query()->with(['actor.roles'])
            ->whereBetween('occurred_at', [$period['start'], $period['end']])
            ->whereHas('intervention', fn (Builder $query) => $query->where('organization_id', $organizationId))->get();
        $items = $alertEvents->concat($interventionEvents)->filter(fn ($event) => $event->actor?->hasRole('operator'))
            ->groupBy('actor_id')->map(function (Collection $group): array {
                $event = $group->first();

                return $this->rankingItem($event->actor_id, $event->actor->name, 'Audited alert and intervention actions', $group->count(), 'actions', '/users/employees?user='.$event->actor_id);
            })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('operators', 'Top operators', 'Ranked by audited alert and intervention actions.', $items);
    }

    private function technicianRanking(Collection $interventions): array
    {
        $items = $interventions->whereNotNull('assigned_technician_id')->groupBy('assigned_technician_id')->map(function (Collection $group): array {
            $intervention = $group->first();

            return $this->rankingItem($intervention->assigned_technician_id, $intervention->assignedTechnician?->name ?? 'Unknown technician', 'Resolved interventions', $group->count(), 'resolved', '/users/employees?user='.$intervention->assigned_technician_id);
        })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('technicians', 'Top technicians', 'Ranked by interventions resolved during the selected period.', $items);
    }

    private function stationRanking(Collection $sessions): array
    {
        $items = $sessions->groupBy('station_id')->map(function (Collection $group): array {
            $session = $group->first();

            return $this->rankingItem($session->station_id, $session->station_name, $group->count().' sessions', round($group->sum('energy_kwh'), 2), 'kWh', '/stations/'.$session->station_id);
        })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('stations', 'Top stations', 'Ranked by delivered energy from sessions started in the selected period.', $items);
    }

    private function regionRanking(Collection $sessions, Collection $stations): array
    {
        $stationCities = $stations->pluck('city', 'id');
        $items = $sessions->groupBy(fn (ChargingSession $session) => $stationCities->get($session->station_id, 'Unknown'))->map(function (Collection $group, string $city): array {
            return $this->rankingItem($city, $city, round($group->sum('energy_kwh'), 2).' kWh delivered', $group->count(), 'sessions', '/stations?city='.urlencode($city));
        })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('regions', 'Top regions', 'Ranked by charging sessions started in the selected period.', $items);
    }

    private function technicianStationRanking(Collection $interventions): array
    {
        $items = $interventions->groupBy('station_id')->map(function (Collection $group): array {
            $intervention = $group->first();

            return $this->rankingItem($intervention->station_id, $intervention->station?->name ?? 'Unknown station', 'Resolved field work', $group->count(), 'resolved', '/stations/'.$intervention->station_id);
        })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('field_stations', 'Stations serviced', 'Resolved work orders by station during the selected period.', $items);
    }

    private function clientStationRanking(Collection $sessions): array
    {
        $items = $sessions->groupBy('station_id')->map(function (Collection $group): array {
            $session = $group->first();

            return $this->rankingItem($session->station_id, $session->station_name, round($group->sum('energy_kwh'), 2).' kWh delivered', $group->count(), 'sessions', '/find-station?station='.$session->station_id);
        })->sortByDesc('value')->take(5)->values()->all();

        return $this->ranking('client_stations', 'My most used stations', 'Ranked by personal sessions in the selected period.', $items);
    }

    /** @return list<array<string, mixed>> */
    private function platformActivities(): array
    {
        $organizations = Organization::query()->latest()->limit(4)->get()->map(fn (Organization $organization) => $this->activity('organization-'.$organization->id, 'organization', 'Organization created', $organization->name, $organization->status, $organization->created_at, '/organizations?organization='.$organization->id));
        $users = User::query()->latest()->limit(4)->get()->map(fn (User $user) => $this->activity('user-'.$user->id, 'user', 'User account created', $user->name, $user->status, $user->created_at, '/admin-users?user='.$user->id));

        return $this->sortActivities($organizations->concat($users));
    }

    /** @return list<array<string, mixed>> */
    private function organizationActivities(int $organizationId, bool $technician): array
    {
        $alerts = Alert::query()->with('station')->where('organization_id', $organizationId)->latest('detected_at')->limit(5)->get()
            ->map(fn (Alert $alert) => $this->activity('alert-'.$alert->id, 'alert', $alert->title, $alert->station?->name ?? $alert->reference, $alert->status, $alert->detected_at, $technician ? '/assigned-alerts?alert='.$alert->id : '/alerts?alert='.$alert->id));
        $sessions = ChargingSession::query()->where('organization_id', $organizationId)->latest('started_at')->limit(5)->get()
            ->map(fn (ChargingSession $session) => $this->activity('session-'.$session->id, 'session', 'Charging session '.$session->status, $session->station_name, $session->status, $session->started_at, '/sessions?session='.$session->id));
        $interventions = Intervention::query()->with('station')->where('organization_id', $organizationId)->latest('updated_at')->limit(5)->get()
            ->map(fn (Intervention $intervention) => $this->activity('intervention-'.$intervention->id, 'intervention', 'Intervention '.$intervention->status, $intervention->station?->name ?? $intervention->reference, $intervention->status, $intervention->updated_at, '/interventions?intervention='.$intervention->id));

        return $this->sortActivities($alerts->concat($sessions)->concat($interventions));
    }

    /** @return list<array<string, mixed>> */
    private function technicianActivities(int $technicianId): array
    {
        $alerts = Alert::query()->with('station')->where('assigned_technician_id', $technicianId)->latest('updated_at')->limit(6)->get()
            ->map(fn (Alert $alert) => $this->activity('alert-'.$alert->id, 'alert', $alert->title, $alert->station?->name ?? $alert->reference, $alert->status, $alert->updated_at, '/assigned-alerts?alert='.$alert->id));
        $interventions = Intervention::query()->with('station')->where('assigned_technician_id', $technicianId)->latest('updated_at')->limit(6)->get()
            ->map(fn (Intervention $intervention) => $this->activity('intervention-'.$intervention->id, 'intervention', $intervention->reference, $intervention->station?->name ?? 'Field work', $intervention->status, $intervention->updated_at, '/my-interventions?intervention='.$intervention->id));

        return $this->sortActivities($alerts->concat($interventions));
    }

    /** @return list<array<string, mixed>> */
    private function clientActivities(int $clientId): array
    {
        $sessions = ChargingSession::query()->where('client_id', $clientId)->latest('started_at')->limit(6)->get()
            ->map(fn (ChargingSession $session) => $this->activity('session-'.$session->id, 'session', $session->reference, $session->station_name, $session->status, $session->started_at, '/my-sessions?session='.$session->id));
        $payments = Payment::query()->with('chargingSession')->where('user_id', $clientId)->latest()->limit(6)->get()
            ->map(fn (Payment $payment) => $this->activity('payment-'.$payment->id, 'payment', $payment->reference, $payment->chargingSession?->station_name ?? 'Charging payment', $payment->status, $payment->paid_at ?? $payment->created_at, '/payments?payment='.$payment->id));

        return $this->sortActivities($sessions->concat($payments));
    }

    /** @return list<array<string, mixed>> */
    private function sortActivities(Collection $activities): array
    {
        return $activities->sortByDesc('occurred_at')->take(8)->values()->all();
    }

    private function activity(string $id, string $type, string $title, string $description, string $status, Carbon $occurredAt, string $actionUrl): array
    {
        return ['id' => $id, 'type' => $type, 'title' => $title, 'description' => $description, 'status' => $status, 'occurred_at' => $occurredAt->toIso8601String(), 'occurred_relative' => $occurredAt->diffForHumans(), 'action_url' => $actionUrl];
    }

    private function breakdown(string $key, string $title, Collection $counts): array
    {
        $total = (int) $counts->sum();
        $items = $counts->map(fn ($count, $status) => ['key' => (string) $status, 'label' => str((string) $status)->replace(['-', '_'], ' ')->title()->toString(), 'count' => (int) $count, 'percentage' => $this->percentage((int) $count, $total)])->values()->all();

        return ['key' => $key, 'title' => $title, 'total' => $total, 'items' => $items];
    }

    private function ranking(string $key, string $title, string $description, array $items): array
    {
        return ['key' => $key, 'title' => $title, 'description' => $description, 'items' => $items];
    }

    private function rankingItem(int|string $id, string $label, string $secondary, int|float $value, string $unit, string $actionUrl): array
    {
        return ['id' => $id, 'label' => $label, 'secondary' => $secondary, 'value' => $value, 'unit' => $unit, 'action_url' => $actionUrl];
    }

    private function kpi(string $key, string $label, int|float $value, string $format, ?float $change = null, string $context = 'Current verified value'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => $format, 'change_percent' => $change, 'context' => $context];
    }

    private function percentage(int|float $part, int|float $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 1) : 0.0;
    }

    private function change(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /** @return array<string, mixed> */
    private function period(string $key): array
    {
        $days = match ($key) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };
        $end = now()->utc();
        $start = $end->copy()->startOfDay()->subDays($days - 1);
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->startOfDay()->subDays($days - 1);

        return [
            'key' => $key,
            'days' => $days,
            'label' => 'Last '.$days.' days',
            'comparison_label' => 'vs previous '.$days.' days',
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ];
    }

    /** @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    private function payload(string $role, array $period, string $headline, string $description, array $kpis, array $trend, array $breakdowns, array $rankings, array $activities, array $methodology, array $widgets = []): array
    {
        return [
            'role' => $role,
            'period' => [
                'key' => $period['key'],
                'days' => $period['days'],
                'label' => $period['label'],
                'comparison_label' => $period['comparison_label'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
            ],
            'headline' => $headline,
            'description' => $description,
            'kpis' => $kpis,
            'trend' => $trend,
            'breakdowns' => $breakdowns,
            'rankings' => array_values(array_filter($rankings, fn (array $ranking) => $ranking['items'] !== [])),
            'recent_activity' => $activities,
            'methodology' => $methodology,
            'widgets' => $widgets,
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }
}
