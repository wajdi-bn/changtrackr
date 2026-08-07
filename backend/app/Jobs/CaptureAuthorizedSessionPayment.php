<?php

namespace App\Jobs;

use App\Models\ChargingSession;
use App\Services\Payments\FailedCaptureRecoveryService;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CaptureAuthorizedSessionPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public int $uniqueFor = 900;

    public function __construct(public readonly int $chargingSessionId)
    {
        $this->onQueue((string) config('queue.names.payments', 'payments'));
    }

    public function uniqueId(): string
    {
        return (string) $this->chargingSessionId;
    }

    public function handle(PaymentService $payments): void
    {
        $session = ChargingSession::query()->find($this->chargingSessionId);
        if ($session !== null) {
            $payments->captureAuthorized($session);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(FailedCaptureRecoveryService::class)->handle($this->chargingSessionId);
    }
}
