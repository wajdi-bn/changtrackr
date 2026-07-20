<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Data\PaymentResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class WireMockPaymentAdapter implements PaymentGateway
{
    public function __construct(private readonly PaymentWebhookSignature $signatures) {}

    public function name(): string
    {
        return 'wiremock';
    }

    public function authorize(PaymentCharge $charge): PaymentResult
    {
        return $this->send('authorize', $charge);
    }

    public function capture(PaymentCharge $charge, string $authorizationId): PaymentResult
    {
        return $this->send('capture', $charge, $authorizationId);
    }

    public function release(string $authorizationId, string $idempotencyKey): PaymentResult
    {
        return $this->send('release', new PaymentCharge(
            paymentReference: $authorizationId,
            amountMillimes: 0,
            currency: 'TND',
            method: 'authorized_funds',
            idempotencyKey: $idempotencyKey,
        ), $authorizationId);
    }

    public function charge(PaymentCharge $charge): PaymentResult
    {
        return $this->send('charge', $charge);
    }

    private function send(string $operation, PaymentCharge $charge, ?string $authorizationId = null): PaymentResult
    {
        $event = $this->eventPayload($operation, $charge, $authorizationId);
        $requestPayload = [
            ...$event,
            'provider_status' => $event['status'],
            'event_type' => $event['type'],
            'method' => $charge->method,
            'simulation_outcome' => $charge->simulationOutcome,
            'webhook_url' => (string) config('payments.simulator.webhook_url'),
            'webhook_signature' => $this->signatures->sign($event),
        ];

        try {
            $response = $this->request()->post((string) config('payments.simulator.operation_endpoint'), $requestPayload);

            return $this->resultFromResponse($response, $operation, $charge);
        } catch (ConnectionException $exception) {
            return new PaymentResult(
                successful: false,
                failureReason: 'The payment provider did not respond before the timeout.',
                metadata: $this->failureMetadata($operation, $charge, 'provider_timeout', true, $exception),
            );
        } catch (Throwable $exception) {
            report($exception);

            return new PaymentResult(
                successful: false,
                failureReason: 'The payment provider could not process the request.',
                metadata: $this->failureMetadata($operation, $charge, 'provider_error', true, $exception),
            );
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('payments.simulator.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Simulator-Api-Key' => (string) config('payments.simulator.api_key'),
            ])
            ->timeout(max(1, (int) config('payments.simulator.timeout_seconds', 3)));

        $retries = max(0, (int) config('payments.simulator.retries', 0));

        return $retries > 0 ? $request->retry($retries + 1, 200, throw: false) : $request;
    }

    private function resultFromResponse(Response $response, string $operation, PaymentCharge $charge): PaymentResult
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $metadata = [
            'mode' => 'external_sandbox',
            'operation' => $operation,
            'method' => $charge->method,
            'idempotency_key' => $charge->idempotencyKey,
            'http_status' => $response->status(),
            'provider_event_id' => $body['event_id'] ?? null,
            'provider_status' => $body['status'] ?? null,
        ];

        if ($response->successful()) {
            return new PaymentResult(
                successful: true,
                transactionId: isset($body['provider_transaction_id']) ? (string) $body['provider_transaction_id'] : null,
                metadata: $metadata,
            );
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $errorCode = (string) ($error['code'] ?? ($response->serverError() ? 'provider_unavailable' : 'payment_declined'));
        $message = (string) ($error['message'] ?? 'The payment provider rejected the request.');

        return new PaymentResult(
            successful: false,
            transactionId: isset($body['provider_transaction_id']) ? (string) $body['provider_transaction_id'] : null,
            failureReason: $message,
            metadata: [...$metadata, 'error_code' => $errorCode, 'retryable' => $response->serverError()],
        );
    }

    /** @return array<string, mixed> */
    private function eventPayload(string $operation, PaymentCharge $charge, ?string $authorizationId): array
    {
        $declined = $charge->simulationOutcome === 'declined';
        $status = $declined ? 'declined' : match ($operation) {
            'authorize' => 'authorized',
            'capture' => 'captured',
            'release' => 'released',
            default => 'paid',
        };
        $prefix = match ($operation) {
            'authorize' => 'auth',
            'capture' => 'cap',
            'release' => 'rel',
            default => 'chg',
        };

        return [
            'event_id' => 'evt_'.$operation.'_'.$charge->idempotencyKey,
            'type' => 'payment.'.$operation.'.'.$status,
            'operation' => $operation,
            'status' => $status,
            'payment_reference' => $charge->paymentReference,
            'provider_transaction_id' => 'sim_'.$prefix.'_'.$charge->idempotencyKey,
            'authorization_id' => $authorizationId ?? '',
            'amount_millimes' => $charge->amountMillimes,
            'currency' => $charge->currency,
            'idempotency_key' => $charge->idempotencyKey,
        ];
    }

    /** @return array<string, mixed> */
    private function failureMetadata(string $operation, PaymentCharge $charge, string $code, bool $retryable, Throwable $exception): array
    {
        return [
            'mode' => 'external_sandbox',
            'operation' => $operation,
            'method' => $charge->method,
            'idempotency_key' => $charge->idempotencyKey,
            'error_code' => $code,
            'retryable' => $retryable,
            'exception' => $exception::class,
        ];
    }
}
