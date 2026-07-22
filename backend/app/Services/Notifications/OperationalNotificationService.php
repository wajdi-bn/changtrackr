<?php

namespace App\Services\Notifications;

use App\Events\UserNotificationCreated;
use App\Jobs\SendOperationalNotificationEmail;
use App\Models\Alert;
use App\Models\Intervention;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OperationalNotificationService
{
    /**
     * @param  array{category:string,severity:string,title:string,message:string,action_url?:?string,entity_type?:?string,entity_id?:?int,deduplication_key:string,data?:array<string,mixed>}  $attributes
     * @param  array<int, string>  $channels
     */
    public function notifyUser(User $user, array $attributes, array $channels = ['in_app']): ?UserNotification
    {
        if ($user->status !== 'active') {
            return null;
        }
        if (in_array('email', $channels, true) && ! $this->wantsEmailFor($user, $attributes['category'])) {
            $channels = array_values(array_diff($channels, ['email']));
        }

        $notification = DB::transaction(function () use ($user, $attributes, $channels): UserNotification {
            $notification = UserNotification::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'deduplication_key' => $attributes['deduplication_key'],
                ],
                [
                    'organization_id' => $user->organization_id,
                    'category' => $attributes['category'],
                    'severity' => $attributes['severity'],
                    'title' => $attributes['title'],
                    'message' => $attributes['message'],
                    'action_url' => $attributes['action_url'] ?? null,
                    'entity_type' => $attributes['entity_type'] ?? null,
                    'entity_id' => $attributes['entity_id'] ?? null,
                    'data' => $attributes['data'] ?? null,
                ],
            );

            if (! $notification->wasRecentlyCreated) {
                return $notification;
            }

            if (in_array('in_app', $channels, true)) {
                $notification->deliveries()->create([
                    'channel' => 'in_app',
                    'status' => 'delivered',
                    'attempts' => 1,
                    'delivered_at' => now(),
                ]);
            }
            if (in_array('email', $channels, true)) {
                $notification->deliveries()->create([
                    'channel' => 'email',
                    'status' => 'pending',
                    'queued_at' => now(),
                ]);
            }

            return $notification;
        });

        if (! $notification->wasRecentlyCreated) {
            return $notification;
        }

        event(UserNotificationCreated::fromNotification($notification));
        $emailDelivery = $notification->deliveries()->where('channel', 'email')->first();
        if ($emailDelivery !== null) {
            SendOperationalNotificationEmail::dispatch($emailDelivery->id);
        }

        return $notification;
    }

    public function notifyAlertOpened(Alert $alert, string|int $occurrence): void
    {
        $channels = $alert->severity === 'critical' ? ['in_app', 'email'] : ['in_app'];
        foreach ($this->organizationUsers($alert->organization_id, ['admin', 'operator']) as $user) {
            $this->notifyUser($user, [
                'category' => 'alert',
                'severity' => $alert->severity,
                'title' => $alert->title,
                'message' => "{$alert->reference} at {$alert->station?->name}: {$alert->description}",
                'action_url' => '/alerts?alert='.$alert->id,
                'entity_type' => Alert::class,
                'entity_id' => $alert->id,
                'deduplication_key' => "alert:{$alert->id}:opened:{$occurrence}",
                'data' => ['station_id' => $alert->station_id, 'reference' => $alert->reference],
            ], $channels);
        }
    }

    public function notifyAlertAssigned(Alert $alert, User $technician, string|int $occurrence): void
    {
        $this->notifyUser($technician, [
            'category' => 'assignment',
            'severity' => $alert->severity,
            'title' => 'Alert assigned to you',
            'message' => "{$alert->reference} at {$alert->station?->name} now requires your attention.",
            'action_url' => '/assigned-alerts?alert='.$alert->id,
            'entity_type' => Alert::class,
            'entity_id' => $alert->id,
            'deduplication_key' => "alert:{$alert->id}:assigned:{$occurrence}:{$technician->id}",
            'data' => ['station_id' => $alert->station_id, 'reference' => $alert->reference],
        ], ['in_app', 'email']);
    }

    public function notifyAlertStatusChanged(Alert $alert, string $previousStatus, string|int $occurrence): void
    {
        $title = $alert->status === 'resolved' ? 'Alert resolved' : 'Alert status updated';
        foreach ($this->alertStakeholders($alert) as $user) {
            $this->notifyUser($user, [
                'category' => 'alert',
                'severity' => $alert->status === 'resolved' ? 'info' : $alert->severity,
                'title' => $title,
                'message' => "{$alert->reference} changed from {$previousStatus} to {$alert->status}.",
                'action_url' => $user->hasRole('technician') ? '/assigned-alerts?alert='.$alert->id : '/alerts?alert='.$alert->id,
                'entity_type' => Alert::class,
                'entity_id' => $alert->id,
                'deduplication_key' => "alert:{$alert->id}:status:{$occurrence}:{$alert->status}",
            ]);
        }
    }

    public function notifyInterventionAssigned(Intervention $intervention, string|int $occurrence): void
    {
        $technician = $intervention->assignedTechnician;
        if ($technician === null) {
            return;
        }

        $this->notifyUser($technician, [
            'category' => 'intervention',
            'severity' => $intervention->priority,
            'title' => $intervention->maintenance_plan_id ? 'Maintenance assigned to you' : 'Intervention assigned to you',
            'message' => "{$intervention->reference} at {$intervention->station?->name} is assigned to you.",
            'action_url' => '/my-interventions?intervention='.$intervention->id,
            'entity_type' => Intervention::class,
            'entity_id' => $intervention->id,
            'deduplication_key' => "intervention:{$intervention->id}:assigned:{$occurrence}:{$technician->id}",
            'data' => ['station_id' => $intervention->station_id, 'reference' => $intervention->reference],
        ], ['in_app', 'email']);
    }

    public function notifyInterventionStatusChanged(Intervention $intervention, string $previousStatus, string|int $occurrence): void
    {
        $technician = $intervention->assignedTechnician;
        if ($technician === null) {
            return;
        }

        $this->notifyUser($technician, [
            'category' => 'intervention',
            'severity' => 'info',
            'title' => 'Intervention status updated',
            'message' => "{$intervention->reference} changed from {$previousStatus} to {$intervention->status}.",
            'action_url' => '/my-interventions?intervention='.$intervention->id,
            'entity_type' => Intervention::class,
            'entity_id' => $intervention->id,
            'deduplication_key' => "intervention:{$intervention->id}:status:{$occurrence}:{$intervention->status}",
        ]);
    }

    public function notifyMaintenanceScheduled(Intervention $intervention): void
    {
        $users = $this->organizationUsers($intervention->organization_id, ['admin', 'operator']);
        if ($intervention->assignedTechnician !== null) {
            $users->push($intervention->assignedTechnician);
        }

        foreach ($users->unique('id') as $user) {
            $this->notifyUser($user, [
                'category' => 'maintenance',
                'severity' => $intervention->priority,
                'title' => 'Maintenance scheduled',
                'message' => "{$intervention->reference} is scheduled at {$intervention->station?->name} for {$intervention->scheduled_at?->format('d M Y H:i')}.",
                'action_url' => $user->hasRole('technician') ? '/my-interventions?intervention='.$intervention->id : '/maintenance',
                'entity_type' => Intervention::class,
                'entity_id' => $intervention->id,
                'deduplication_key' => "maintenance:{$intervention->id}:scheduled:{$intervention->scheduled_at?->timestamp}",
            ]);
        }
    }

    public function notifyMaintenanceDue(Intervention $intervention): void
    {
        $users = $this->organizationUsers($intervention->organization_id, ['admin', 'operator']);
        if ($intervention->assignedTechnician !== null) {
            $users->push($intervention->assignedTechnician);
        }

        foreach ($users->unique('id') as $user) {
            $this->notifyUser($user, [
                'category' => 'maintenance',
                'severity' => 'warning',
                'title' => 'Maintenance due within 24 hours',
                'message' => "{$intervention->reference} at {$intervention->station?->name} is scheduled for {$intervention->scheduled_at?->format('d M Y H:i')}.",
                'action_url' => $user->hasRole('technician') ? '/my-interventions?intervention='.$intervention->id : '/maintenance',
                'entity_type' => Intervention::class,
                'entity_id' => $intervention->id,
                'deduplication_key' => "maintenance:{$intervention->id}:due:{$intervention->scheduled_at?->timestamp}",
            ]);
        }
    }

    public function notifyAlertSla(Alert $alert, string $stage): void
    {
        $timing = $stage === 'overdue' ? 'past its due time' : 'due within five minutes';
        foreach ($this->alertStakeholders($alert) as $user) {
            $this->notifyUser($user, [
                'category' => 'sla',
                'severity' => $stage === 'overdue' ? 'critical' : 'warning',
                'title' => $stage === 'overdue' ? 'Alert SLA exceeded' : 'Alert SLA is approaching',
                'message' => "{$alert->reference} at {$alert->station?->name} is {$timing}.",
                'action_url' => $user->hasRole('technician') ? '/assigned-alerts?alert='.$alert->id : '/alerts?alert='.$alert->id,
                'entity_type' => Alert::class,
                'entity_id' => $alert->id,
                'deduplication_key' => "alert:{$alert->id}:sla:{$stage}",
            ], $stage === 'overdue' ? ['in_app', 'email'] : ['in_app']);
        }
    }

    public function notifyPaymentFailure(Payment $payment): void
    {
        $payment->loadMissing(['user', 'chargingSession']);
        $errorCode = (string) data_get($payment->metadata, 'error_code', 'payment_declined');
        $technical = in_array($errorCode, ['provider_timeout', 'provider_unavailable', 'provider_error'], true);
        if ($payment->user !== null) {
            $this->notifyUser($payment->user, [
                'category' => 'payment',
                'severity' => $technical ? 'warning' : 'critical',
                'title' => $technical ? 'Payment service temporarily unavailable' : 'Payment was declined',
                'message' => "Payment {$payment->reference} for {$payment->chargingSession?->station_name} was not completed. {$payment->failure_reason}",
                'action_url' => '/payments',
                'entity_type' => Payment::class,
                'entity_id' => $payment->id,
                'deduplication_key' => "payment:{$payment->id}:failed:{$payment->idempotency_key}",
            ], ['in_app', 'email']);
        }

        if ($technical) {
            foreach ($this->organizationUsers($payment->organization_id, ['admin', 'operator']) as $user) {
                $this->notifyUser($user, [
                    'category' => 'payment',
                    'severity' => 'warning',
                    'title' => 'Payment provider incident',
                    'message' => "Payment {$payment->reference} failed because the provider is unavailable.",
                    'action_url' => '/payments',
                    'entity_type' => Payment::class,
                    'entity_id' => $payment->id,
                    'deduplication_key' => "payment:{$payment->id}:provider-incident:{$payment->idempotency_key}",
                ]);
            }
        }
    }

    /** @param array<int, string> $roles
     * @return Collection<int, User>
     */
    private function organizationUsers(int $organizationId, array $roles): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->get();
    }

    /** @return Collection<int, User> */
    private function alertStakeholders(Alert $alert): Collection
    {
        $users = $this->organizationUsers($alert->organization_id, ['admin', 'operator']);
        if ($alert->assignedTechnician !== null) {
            $users->push($alert->assignedTechnician);
        }

        return $users->unique('id')->values();
    }

    private function wantsEmailFor(User $user, string $category): bool
    {
        $key = match ($category) {
            'assignment' => 'email_assignments',
            'intervention' => 'email_interventions',
            'maintenance' => 'email_maintenance',
            'sla' => 'email_sla',
            'payment' => 'email_payments',
            default => 'email_alerts',
        };

        return (bool) data_get($user->notification_preferences, $key, true);
    }
}
