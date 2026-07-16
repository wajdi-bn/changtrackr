<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['organization_id', 'name', 'email', 'phone', 'avatar_url', 'team', 'address', 'status', 'password', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ORGANIZATION_ROLES = ['admin', 'operator', 'technician'];

    public const GLOBAL_ROLES = ['super_admin', 'client'];

    public const EMPLOYEE_ROLES = ['super_admin', ...self::ORGANIZATION_ROLES];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Alert, $this> */
    public function assignedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'assigned_technician_id');
    }

    /** @return HasMany<Intervention, $this> */
    public function assignedInterventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'assigned_technician_id');
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class, 'client_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }

    public function primaryRoleName(): ?string
    {
        $roleNames = $this->getRoleNames();

        return $roleNames->count() === 1 ? $roleNames->first() : null;
    }

    public function canAccessOrganization(?int $organizationId): bool
    {
        return $this->hasRole('super_admin')
            || ($organizationId !== null && $this->organization_id === $organizationId);
    }

    public function hasValidOrganizationAssignment(): bool
    {
        $roleNames = $this->getRoleNames();
        if ($roleNames->count() !== 1) {
            return false;
        }

        $role = $roleNames->first();
        if (in_array($role, self::GLOBAL_ROLES, true)) {
            return $this->organization_id === null;
        }

        if (! in_array($role, self::ORGANIZATION_ROLES, true)) {
            return false;
        }

        $this->loadMissing('organization');

        return $this->organization_id !== null && $this->organization?->status === 'active';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
