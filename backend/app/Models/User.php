<?php

namespace App\Models;

use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyClientEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['organization_id', 'name', 'email', 'email_verified_at', 'phone', 'avatar_url', 'team', 'address', 'job_title', 'bio', 'address_line_1', 'address_line_2', 'city', 'region', 'postal_code', 'country_code', 'locale', 'timezone', 'linkedin_url', 'website_url', 'status', 'password', 'password_login_enabled', 'last_login_at', 'notification_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
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

    /** @return HasMany<MaintenancePlan, $this> */
    public function assignedMaintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class, 'assigned_technician_id');
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

    public function ocppIdTags(): HasMany
    {
        return $this->hasMany(OcppIdTag::class);
    }

    public function chargingAttempts(): HasMany
    {
        return $this->hasMany(ChargingAttempt::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function accountInvitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    public function latestAccountInvitation(): HasOne
    {
        return $this->hasOne(AccountInvitation::class)->latestOfMany();
    }

    public function sentAccountInvitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class, 'invited_by_id');
    }

    public function operationalNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function sentInternalReports(): HasMany
    {
        return $this->hasMany(InternalReport::class, 'sender_id');
    }

    public function receivedInternalReports(): HasMany
    {
        return $this->hasMany(InternalReport::class, 'recipient_id');
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
        $this->unsetRelation('roles');
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

        $this->unsetRelation('organization');
        $this->load('organization');

        return $this->organization_id !== null && $this->organization?->status === 'active';
    }

    public function requiresClientEmailVerification(): bool
    {
        return $this->hasRole('client') && ! $this->hasVerifiedEmail();
    }

    public function hasLocalPasswordLogin(): bool
    {
        return $this->password_login_enabled ?? true;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyClientEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetAccountPassword($token));
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
            'notification_preferences' => 'array',
            'password_login_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
