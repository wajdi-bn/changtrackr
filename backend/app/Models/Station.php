<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'name', 'reference', 'ocpp_identity', 'location_name', 'city', 'address',
    'latitude', 'longitude', 'status', 'max_power_kw', 'model', 'manufacturer',
    'availability_override', 'maintenance_intervention_id', 'status_before_maintenance',
    'availability_reason', 'availability_source',
    'availability_calculated_at', 'availability_monitoring_started_at',
    'ocpp_version', 'ocpp_commissioning_target', 'ocpp_auth_secret_hash',
    'ocpp_registration_status', 'ocpp_status',
    'ocpp_error_code', 'ocpp_connected_at', 'ocpp_disconnected_at', 'ocpp_last_message_at',
    'ocpp_last_status_at', 'model_image', 'last_heartbeat_at', 'uptime_percent',
    'utilization_percent', 'open_alerts_count',
])]
class Station extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $hidden = ['ocpp_auth_secret_hash'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Connector, $this> */
    public function connectors(): HasMany
    {
        return $this->hasMany(Connector::class);
    }

    public function ocppCommands(): HasMany
    {
        return $this->hasMany(OcppCommand::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return HasMany<Intervention, $this> */
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class);
    }

    /** @return HasMany<MaintenancePlan, $this> */
    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class);
    }

    /** @return BelongsTo<Intervention, $this> */
    public function activeMaintenanceIntervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class, 'maintenance_intervention_id');
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    /** @param Builder<Station> $query */
    public function scopeWithTodayMetrics(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $timezone = (string) config('station_metrics.timezone', 'Africa/Tunis');
        $localNow = $at === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($at)->setTimezone($timezone);
        $startsAt = $localNow->startOfDay()->utc();
        $endsAt = $localNow->addDay()->startOfDay()->utc();

        return $query->addSelect([
            'daily_energy_kwh' => ChargingSession::query()
                ->selectRaw('COALESCE(SUM(energy_kwh), 0)')
                ->whereColumn('charging_sessions.station_id', 'stations.id')
                ->whereIn('status', ['completed', 'interrupted'])
                ->where('ended_at', '>=', $startsAt)
                ->where('ended_at', '<', $endsAt),
            'daily_sessions_count' => ChargingSession::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('charging_sessions.station_id', 'stations.id')
                ->whereIn('status', ['completed', 'interrupted'])
                ->where('ended_at', '>=', $startsAt)
                ->where('ended_at', '<', $endsAt),
            'daily_revenue_millimes' => Payment::query()
                ->selectRaw('COALESCE(SUM(payments.amount_millimes), 0)')
                ->join('charging_sessions', 'charging_sessions.id', '=', 'payments.charging_session_id')
                ->whereColumn('charging_sessions.station_id', 'stations.id')
                ->where('payments.status', 'paid')
                ->where('payments.paid_at', '>=', $startsAt)
                ->where('payments.paid_at', '<', $endsAt),
        ]);
    }

    public function loadTodayMetrics(?CarbonInterface $at = null): static
    {
        $metrics = static::query()->withTodayMetrics($at)->findOrFail($this->getKey());

        foreach (['daily_energy_kwh', 'daily_sessions_count', 'daily_revenue_millimes'] as $attribute) {
            $this->setAttribute($attribute, $metrics->getAttribute($attribute));
        }

        return $this;
    }

    /** @return HasMany<OcppEvent, $this> */
    public function ocppEvents(): HasMany
    {
        return $this->hasMany(OcppEvent::class);
    }

    public function ocppTransactions(): HasMany
    {
        return $this->hasMany(OcppTransaction::class);
    }

    /** @return HasMany<AvailabilityTransition, $this> */
    public function availabilityTransitions(): HasMany
    {
        return $this->hasMany(AvailabilityTransition::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(AssetDocument::class, 'documentable');
    }

    public function isOcppManaged(): bool
    {
        return $this->ocpp_version === 'OCPP 1.6J' && $this->ocpp_auth_secret_hash !== null;
    }

    public function commissioningStatus(): string
    {
        if (! $this->isOcppManaged()) {
            return 'not_provisioned';
        }

        if ($this->ocpp_registration_status === 'rejected') {
            return 'rejected';
        }

        if ($this->hasFreshOcppConnection()) {
            return 'connected';
        }

        if ($this->ocpp_connected_at !== null || $this->ocpp_registration_status === 'accepted') {
            return 'offline';
        }

        return 'awaiting_connection';
    }

    public function hasFreshOcppConnection(): bool
    {
        if (! $this->isOcppManaged()) {
            return false;
        }

        if ($this->ocpp_disconnected_at !== null
            && ($this->ocpp_connected_at === null || $this->ocpp_disconnected_at->greaterThanOrEqualTo($this->ocpp_connected_at))) {
            return false;
        }

        $lastContact = collect([
            $this->last_heartbeat_at,
            $this->ocpp_last_message_at,
            $this->ocpp_connected_at,
        ])->filter()->sortByDesc(fn ($date) => $date->getTimestamp())->first();

        return $lastContact !== null
            && $lastContact->gte(now()->subSeconds(max(1, (int) config('availability.communication_timeout_seconds', 90))));
    }

    public function canStartRemotely(): bool
    {
        return $this->remoteStartUnavailableReason() === null;
    }

    public function remoteStartUnavailableReason(): ?string
    {
        if (! $this->isOcppManaged()) {
            return 'not_ocpp_managed';
        }

        $organizationStatus = $this->relationLoaded('organization')
            ? $this->organization?->status
            : $this->organization()->value('status');
        if ($organizationStatus !== 'active') {
            return 'organization_inactive';
        }

        if ($this->availability_override === 'maintenance' || $this->status === 'maintenance') {
            return 'maintenance';
        }

        if ($this->availability_override === 'disabled') {
            return 'disabled';
        }

        if (! $this->hasFreshOcppConnection() || $this->status === 'offline') {
            return 'station_offline';
        }

        if ($this->status !== 'available') {
            return 'station_unavailable';
        }

        $hasAvailableConnector = $this->relationLoaded('connectors')
            ? $this->connectors->contains(
                fn (Connector $connector): bool => $connector->status === 'available'
                    && $connector->ocpp_status === 'Available'
            )
            : $this->connectors()
                ->where('status', 'available')
                ->where('ocpp_status', 'Available')
                ->exists();

        return $hasAvailableConnector ? null : 'no_available_connector';
    }

    public function tariffAssignment(): HasOne
    {
        return $this->hasOne(TariffAssignment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'max_power_kw' => 'float',
            'last_heartbeat_at' => 'datetime',
            'ocpp_connected_at' => 'datetime',
            'ocpp_disconnected_at' => 'datetime',
            'ocpp_last_message_at' => 'datetime',
            'ocpp_last_status_at' => 'datetime',
            'availability_calculated_at' => 'datetime',
            'availability_monitoring_started_at' => 'datetime',
            'uptime_percent' => 'float',
            'utilization_percent' => 'float',
            'open_alerts_count' => 'integer',
        ];
    }
}
