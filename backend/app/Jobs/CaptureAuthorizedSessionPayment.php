<?php

namespace App\Jobs;

use App\Models\ChargingSession;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CaptureAuthorizedSessionPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $chargingSessionId) {}

    public function handle(PaymentService $payments): void
    {
        $session = ChargingSession::query()->find($this->chargingSessionId);
        if ($session !== null) {
            $payments->captureAuthorized($session);
        }
    }
}
