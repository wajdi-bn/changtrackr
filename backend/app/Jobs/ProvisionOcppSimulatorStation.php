<?php

namespace App\Jobs;

use App\Models\Station;
use App\Services\Ocpp\OcppSimulatorControlClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionOcppSimulatorStation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [2, 5, 10];

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $stationId)
    {
        $this->afterCommit();
        $this->onQueue((string) config('queue.names.default', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->stationId;
    }

    public function handle(OcppSimulatorControlClient $client): void
    {
        $station = Station::query()->find($this->stationId);
        if ($station === null
            || $station->ocpp_commissioning_target !== 'simulator'
            || $station->ocpp_simulator_profile === null) {
            return;
        }

        $station->update([
            'ocpp_provisioning_status' => 'provisioning',
            'ocpp_provisioning_error' => null,
        ]);

        $client->provision((string) $station->ocpp_identity, $station->ocpp_simulator_profile);

        $station->update([
            'ocpp_provisioning_status' => 'provisioned',
            'ocpp_provisioning_error' => null,
            'ocpp_provisioned_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Station::query()
            ->whereKey($this->stationId)
            ->where('ocpp_commissioning_target', 'simulator')
            ->update([
                'ocpp_provisioning_status' => 'failed',
                'ocpp_provisioning_error' => 'The simulator could not provision this station. Retry when the simulator service is available.',
            ]);

        if ($exception !== null) {
            report($exception);
        }
    }
}
