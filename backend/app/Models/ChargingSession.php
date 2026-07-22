<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'client_id', 'station_id', 'connector_id', 'tariff_id', 'charging_plan_id',
    'ocpp_transaction_id', 'reference', 'source', 'client_name', 'station_name',
    'connector_external_id', 'status', 'lifecycle_reason', 'payment_status',
    'started_at', 'ended_at', 'duration_seconds', 'meter_start_kwh', 'meter_stop_kwh',
    'last_meter_value_at', 'energy_kwh', 'current_power_kw', 'state_of_charge_percent',
    'limit_energy_kwh', 'limit_amount_millimes', 'limit_duration_minutes',
    'tariff_name', 'charging_plan_name', 'discount_basis_points', 'discount_millimes',
    'price_per_kwh_millimes', 'session_fee_millimes',
    'idle_fee_per_minute_millimes', 'minimum_charge_millimes', 'total_millimes', 'currency',
])]
class ChargingSession extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function chargingPlan(): BelongsTo
    {
        return $this->belongsTo(ChargingPlan::class);
    }

    public function ocppTransaction(): BelongsTo
    {
        return $this->belongsTo(OcppTransaction::class);
    }

    public function chargingAttempt(): HasOne
    {
        return $this->hasOne(ChargingAttempt::class);
    }

    public function ocppCommands(): HasMany
    {
        return $this->hasMany(OcppCommand::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'meter_start_kwh' => 'float',
            'meter_stop_kwh' => 'float',
            'last_meter_value_at' => 'datetime',
            'energy_kwh' => 'float',
            'current_power_kw' => 'float',
            'state_of_charge_percent' => 'float',
            'limit_energy_kwh' => 'float',
            'limit_amount_millimes' => 'integer',
            'limit_duration_minutes' => 'integer',
            'discount_basis_points' => 'integer',
            'discount_millimes' => 'integer',
            'price_per_kwh_millimes' => 'integer',
            'session_fee_millimes' => 'integer',
            'idle_fee_per_minute_millimes' => 'integer',
            'minimum_charge_millimes' => 'integer',
            'total_millimes' => 'integer',
        ];
    }
}
