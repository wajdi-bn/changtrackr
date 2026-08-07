<?php

namespace App\Events;

use App\Events\Concerns\RoutesBroadcastsToQueue;
use App\Models\UserNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserNotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, RoutesBroadcastsToQueue, SerializesModels;

    public function __construct(
        public readonly int $notificationId,
        public readonly int $userId,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $createdAt,
    ) {}

    public static function fromNotification(UserNotification $notification): self
    {
        return new self(
            notificationId: $notification->id,
            userId: $notification->user_id,
            category: $notification->category,
            severity: $notification->severity,
            title: $notification->title,
            createdAt: $notification->created_at->toISOString(),
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}.notifications");
    }

    public function broadcastAs(): string
    {
        return 'user-notification.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'created_at' => $this->createdAt,
        ];
    }
}
