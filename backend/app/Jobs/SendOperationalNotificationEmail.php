<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Notifications\OperationalEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendOperationalNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $deliveryId)
    {
        $this->afterCommit();
        $this->onQueue((string) config('queue.names.emails', 'emails'));
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()
            ->with('notification.user')
            ->find($this->deliveryId);
        if ($delivery === null || $delivery->status === 'delivered') {
            return;
        }

        $delivery->update([
            'status' => 'processing',
            'attempts' => $delivery->attempts + 1,
            'error_message' => null,
            'failed_at' => null,
        ]);

        try {
            $delivery->notification->user->notifyNow(
                new OperationalEmailNotification($delivery->notification),
            );
            $delivery->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'failed_at' => null,
            ]);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'failed_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'error_message' => $exception ? mb_substr($exception->getMessage(), 0, 2000) : 'Email delivery failed.',
            'failed_at' => now(),
        ]);
    }
}
