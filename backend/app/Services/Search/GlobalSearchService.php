<?php

namespace App\Services\Search;

use App\Models\Alert;
use App\Models\ChargingSession;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GlobalSearchService
{
    /**
     * @return array{data: Collection<int, array<string, mixed>>, summary: array{total: int, groups: array<string, int>}}
     */
    public function search(User $user, string $term, int $limit): array
    {
        $needle = '%'.mb_strtolower($term).'%';
        $groups = collect();

        if ($user->hasRole('super_admin')) {
            $groups->put('Organizations', $this->organizations($needle, $limit));
        }
        if ($user->can('users.view')) {
            $groups->put('People', $this->people($user, $needle, $limit));
        }
        if ($user->can('stations.view')) {
            $groups->put('Stations', $this->stations($user, $needle, $limit));
        }
        if ($user->can('alerts.view')) {
            $groups->put('Alerts', $this->alerts($user, $needle, $limit));
        }
        if ($user->can('interventions.view')) {
            $groups->put('Interventions', $this->interventions($user, $needle, $limit));
        }
        if ($user->can('sessions.view')) {
            $groups->put('Sessions', $this->sessions($user, $needle, $limit));
        }
        if ($user->can('payments.view')) {
            $groups->put('Payments', $this->payments($user, $needle, $limit));
        }

        $groups = $groups->filter(fn (Collection $items) => $items->isNotEmpty());
        $items = $groups->flatten(1)->values();

        return [
            'data' => $items,
            'summary' => [
                'total' => $items->count(),
                'groups' => $groups->map->count()->all(),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function organizations(string $needle, int $limit): Collection
    {
        return Organization::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(slug) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(contact_email) LIKE ?', [$needle]))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Organization $organization) => $this->result(
                'organization',
                $organization->id,
                'Organizations',
                $organization->name,
                $organization->contact_email ?? $organization->slug,
                $organization->status,
                '/organizations?search='.rawurlencode($organization->name),
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function people(User $actor, string $needle, int $limit): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', User::EMPLOYEE_ROLES))
            ->when(! $actor->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $actor->organization_id))
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(team) LIKE ?', [$needle]))
            ->with(['organization', 'roles:id,name'])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $person) => $this->result(
                'user',
                $person->id,
                'People',
                $person->name,
                collect([$person->email, $person->organization?->name])->filter()->join(' · '),
                $person->status,
                ($actor->hasRole('super_admin') ? '/admin-users' : '/users/employees').'?search='.rawurlencode($person->email),
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function stations(User $user, string $needle, int $limit): Collection
    {
        return Station::query()
            ->when(
                $user->hasRole('client'),
                fn (Builder $query) => $query->whereHas('organization', fn (Builder $query) => $query->where('status', 'active')),
            )
            ->when(
                ! $user->hasAnyRole(['super_admin', 'client']),
                fn (Builder $query) => $query->where('organization_id', $user->organization_id),
            )
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(reference) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(city) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(location_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(ocpp_identity) LIKE ?', [$needle]))
            ->with('organization')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Station $station) => $this->result(
                'station',
                $station->id,
                'Stations',
                $station->name,
                collect([$station->reference, $station->city, $station->organization?->name])->filter()->join(' · '),
                $station->status,
                '/stations/'.$station->id,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function alerts(User $user, string $needle, int $limit): Collection
    {
        return Alert::query()
            ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
            ->when($user->hasRole('technician'), fn (Builder $query) => $query->where('assigned_technician_id', $user->id))
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(reference) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(problem_type) LIKE ?', [$needle])
                ->orWhereHas('station', fn (Builder $stationQuery) => $stationQuery->whereRaw('LOWER(name) LIKE ?', [$needle])))
            ->with('station')
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get()
            ->map(fn (Alert $alert) => $this->result(
                'alert',
                $alert->id,
                'Alerts',
                $alert->title,
                collect([$alert->reference, $alert->station?->name, ucfirst($alert->severity)])->filter()->join(' · '),
                $alert->status,
                ($user->hasRole('technician') ? '/assigned-alerts' : '/alerts').'?alert='.$alert->id,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function interventions(User $user, string $needle, int $limit): Collection
    {
        return Intervention::query()
            ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
            ->when($user->hasRole('technician'), fn (Builder $query) => $query->where('assigned_technician_id', $user->id))
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(reference) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(problem) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(diagnosis) LIKE ?', [$needle])
                ->orWhereHas('station', fn (Builder $stationQuery) => $stationQuery->whereRaw('LOWER(name) LIKE ?', [$needle])))
            ->with('station')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Intervention $intervention) => $this->result(
                'intervention',
                $intervention->id,
                'Interventions',
                $intervention->reference,
                collect([$intervention->station?->name, $intervention->problem])->filter()->join(' · '),
                $intervention->status,
                ($user->hasRole('technician') ? '/my-interventions' : '/interventions').'?intervention='.$intervention->id,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function sessions(User $user, string $needle, int $limit): Collection
    {
        return ChargingSession::query()
            ->when(! $user->hasRole('super_admin'), function (Builder $query) use ($user): void {
                $user->hasRole('client')
                    ? $query->where('client_id', $user->id)
                    : $query->where('organization_id', $user->organization_id);
            })
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(reference) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(client_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(station_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(connector_external_id) LIKE ?', [$needle]))
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (ChargingSession $session) => $this->result(
                'session',
                $session->id,
                'Sessions',
                $session->reference,
                collect([$session->station_name, $session->client_name, $session->connector_external_id])->filter()->join(' · '),
                $session->status,
                ($user->hasRole('client') ? '/my-sessions' : '/sessions').'?search='.rawurlencode($session->reference),
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function payments(User $user, string $needle, int $limit): Collection
    {
        return Payment::query()
            ->when(! $user->hasRole('super_admin'), function (Builder $query) use ($user): void {
                $user->hasRole('client')
                    ? $query->where('user_id', $user->id)
                    : $query->where('organization_id', $user->organization_id);
            })
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(reference) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(provider_transaction_id) LIKE ?', [$needle])
                ->orWhereHas('chargingSession', fn (Builder $sessionQuery) => $sessionQuery
                    ->whereRaw('LOWER(reference) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(station_name) LIKE ?', [$needle])))
            ->with('chargingSession')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Payment $payment) => $this->result(
                'payment',
                $payment->id,
                'Payments',
                $payment->reference,
                collect([$payment->chargingSession?->station_name, $payment->provider, $payment->method])->filter()->join(' · '),
                $payment->status,
                '/payments?search='.rawurlencode($payment->reference),
            ));
    }

    /** @return array<string, mixed> */
    private function result(
        string $type,
        int $id,
        string $group,
        string $title,
        string $subtitle,
        ?string $status,
        string $url,
    ): array {
        return compact('type', 'id', 'group', 'title', 'subtitle', 'status', 'url');
    }
}
