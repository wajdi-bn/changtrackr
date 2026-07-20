<?php

namespace App\Services\Payments;

use JsonException;

class PaymentWebhookSignature
{
    /** @param array<string, mixed> $payload */
    public function sign(array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalJson($payload), $this->secret());
    }

    /** @param array<string, mixed> $payload */
    public function verify(array $payload, ?string $signature): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $signature = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals($this->sign($payload), $signature);
    }

    /** @param array<string, mixed> $payload
     * @throws JsonException
     */
    private function canonicalJson(array $payload): string
    {
        return json_encode([
            'event_id' => (string) ($payload['event_id'] ?? ''),
            'type' => (string) ($payload['type'] ?? ''),
            'operation' => (string) ($payload['operation'] ?? ''),
            'status' => (string) ($payload['status'] ?? ''),
            'payment_reference' => (string) ($payload['payment_reference'] ?? ''),
            'provider_transaction_id' => (string) ($payload['provider_transaction_id'] ?? ''),
            'authorization_id' => (string) ($payload['authorization_id'] ?? ''),
            'amount_millimes' => (int) ($payload['amount_millimes'] ?? 0),
            'currency' => (string) ($payload['currency'] ?? ''),
            'idempotency_key' => (string) ($payload['idempotency_key'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function secret(): string
    {
        return (string) config('payments.simulator.webhook_secret');
    }
}
