<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'contact_email', 'contact_phone', 'status', 'settings'])]
class Organization extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Station, $this> */
    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
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

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    public function chargingPlans(): HasMany
    {
        return $this->hasMany(ChargingPlan::class);
    }

    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }

    public function demoRequests(): HasMany
    {
        return $this->hasMany(DemoRequest::class);
    }

    public function accountInvitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    public function internalReports(): HasMany
    {
        return $this->hasMany(InternalReport::class);
    }

    public function commercialSubscription(): HasOne
    {
        return $this->hasOne(OrganizationSubscription::class);
    }

    public function commercialInvoices(): HasMany
    {
        return $this->hasMany(OrganizationInvoice::class);
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value, array $attributes) => $value ?: Str::slug($attributes['name'] ?? Str::random(8)),
        );
    }
}
