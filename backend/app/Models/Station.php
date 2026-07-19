<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'name', 'reference', 'ocpp_identity', 'location_name', 'city', 'address',
    'latitude', 'longitude', 'status', 'max_power_kw', 'model', 'manufacturer',
    'availability_override', 'availability_reason', 'availability_source',
    'availability_calculated_at', 'availability_monitoring_started_at',
    'ocpp_version', 'ocpp_auth_secret_hash', 'ocpp_registration_status', 'ocpp_status',
    'ocpp_error_code', 'ocpp_connected_at', 'ocpp_disconnected_at', 'ocpp_last_message_at',
    'ocpp_last_status_at', 'model_image', 'last_heartbeat_at', 'uptime_percent',
    'energy_today_kwh', 'sessions_today', 'utilization_percent', 'revenue_today',
    'open_alerts_count',
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

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
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

    public function isOcppManaged(): bool
    {
        return $this->ocpp_version === 'OCPP 1.6J' && $this->ocpp_auth_secret_hash !== null;
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
            'energy_today_kwh' => 'float',
            'sessions_today' => 'integer',
            'utilization_percent' => 'float',
            'revenue_today' => 'float',
            'open_alerts_count' => 'integer',
        ];
    }
}
