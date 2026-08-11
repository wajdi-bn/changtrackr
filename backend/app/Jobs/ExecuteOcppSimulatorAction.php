<?php

namespace App\Jobs;

use App\Models\OcppSimulatorAction;
use App\Services\Ocpp\OcppSimulatorControlClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteOcppSimulatorAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $simulatorActionId)
    {
        $this->afterCommit();
        $this->onQueue((string) config('queue.names.default', 'default'));
    }

    public function handle(OcppSimulatorControlClient $client): void
    {
        $action = OcppSimulatorAction::query()->with('station')->find($this->simulatorActionId);
        if ($action === null || $action->status !== 'queued') {
            return;
        }

        $action->update(['status' => 'running', 'started_at' => now()]);

        try {
            $result = $client->execute(
                (string) $action->station->ocpp_identity,
                $action->action,
                $action->request_payload['connector_id'] ?? null,
            );
            $action->update([
                'status' => 'succeeded',
                'result_payload' => $result,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $action->update([
                'status' => 'failed',
                'failure_code' => 'simulator_action_failed',
                'failure_message' => 'The simulator could not execute this action.',
                'completed_at' => now(),
            ]);
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        OcppSimulatorAction::query()
            ->whereKey($this->simulatorActionId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'failure_code' => 'worker_failed',
                'failure_message' => 'The simulator action worker failed.',
                'completed_at' => now(),
            ]);
    }
}
