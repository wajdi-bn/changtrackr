<?php

namespace App\Services\Ocpp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OcppSimulatorControlClient
{
    /** @return list<array<string, mixed>> */
    public function profiles(): array
    {
        return $this->request()
            ->get('/profiles')
            ->throw()
            ->json('data');
    }

    /** @return array<string, mixed> */
    public function provision(string $identity, string $profile): array
    {
        return $this->request()
            ->post('/stations', [
                'identity' => $identity,
                'profile' => $profile,
            ])
            ->throw()
            ->json('data');
    }

    /** @return array<string, mixed> */
    public function state(string $identity): array
    {
        return $this->request()
            ->get('/stations/'.rawurlencode($identity))
            ->throw()
            ->json('data');
    }

    /** @return array<string, mixed> */
    public function execute(string $identity, string $action, ?int $connectorId): array
    {
        $payload = ['action' => $action];
        if ($connectorId !== null) {
            $payload['connector_id'] = $connectorId;
        }

        return $this->request()
            ->post('/stations/'.rawurlencode($identity).'/actions', $payload)
            ->throw()
            ->json('data');
    }

    private function request(): PendingRequest
    {
        $baseUrl = rtrim((string) config('ocpp.simulator.control_url'), '/');
        $token = (string) config('ocpp.simulator.control_token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('The OCPP simulator control service is not configured.');
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withToken($token)
            ->timeout(max(1, (int) config('ocpp.simulator.control_timeout_seconds', 15)));
    }
}
