<?php

namespace App\Services\Notifications;

use App\Models\Alert;
use App\Models\Intervention;

class NotificationSlaService
{
    public function __construct(private readonly OperationalNotificationService $notifications) {}

    /** @return array{approaching: int, overdue: int, maintenance_due: int} */
    public function scan(): array
    {
        $now = now()->utc();
        $approaching = Alert::query()
            ->with(['station', 'assignedTechnician'])
            ->where('status', '!=', 'resolved')
            ->whereNotNull('due_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->copy()->addMinutes(5))
            ->get();
        $overdue = Alert::query()
            ->with(['station', 'assignedTechnician'])
            ->where('status', '!=', 'resolved')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->get();
        $maintenance = Intervention::query()
            ->with(['station', 'assignedTechnician'])
            ->whereNotNull('maintenance_plan_id')
            ->where('status', 'assigned')
            ->whereBetween('scheduled_at', [$now, $now->copy()->addDay()])
            ->get();

        $approaching->each(fn (Alert $alert) => $this->notifications->notifyAlertSla($alert, 'approaching'));
        $overdue->each(fn (Alert $alert) => $this->notifications->notifyAlertSla($alert, 'overdue'));
        $maintenance->each(fn (Intervention $intervention) => $this->notifications->notifyMaintenanceDue($intervention));

        return [
            'approaching' => $approaching->count(),
            'overdue' => $overdue->count(),
            'maintenance_due' => $maintenance->count(),
        ];
    }
}
