<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OcppEvent;
use App\Models\PaymentProviderEvent;
use App\Models\SocialAccount;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformIntegrationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('super_admin') && $request->user()->can('integrations.manage'), 403);

        $integrations = [
            $this->googleIntegration(),
            $this->emailIntegration(),
            $this->ocppIntegration(),
            $this->paymentIntegration(),
            $this->mappingIntegration(),
        ];

        return response()->json([
            'data' => $integrations,
            'summary' => [
                'total' => count($integrations),
                'operational' => collect($integrations)->whereIn('status', ['operational', 'configured'])->count(),
                'attention' => collect($integrations)->where('status', 'attention')->count(),
                'sandbox' => collect($integrations)->where('mode', 'Sandbox')->count(),
            ],
            'checked_at' => now()->toISOString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function googleIntegration(): array
    {
        $configured = filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
        $latestAccount = SocialAccount::query()->where('provider', 'google')->latest()->first();

        return $this->integration(
            id: 'google-oauth',
            name: 'Google OAuth',
            category: 'Authentication',
            provider: 'Google OAuth 2.0',
            description: 'Secure social sign-in for client accounts without storing a Google password.',
            status: $configured ? 'configured' : 'attention',
            mode: app()->environment('production') ? 'Production' : 'Development',
            configured: $configured,
            lastActivity: $latestAccount?->created_at?->toISOString(),
            metrics: [
                ['label' => 'Linked accounts', 'value' => SocialAccount::query()->where('provider', 'google')->count()],
                ['label' => 'Callback host', 'value' => parse_url((string) config('services.google.redirect'), PHP_URL_HOST) ?: 'Not set'],
            ],
            safeguards: ['Client secret remains in the deployment environment', 'Callback validation is handled server-side'],
        );
    }

    /** @return array<string, mixed> */
    private function emailIntegration(): array
    {
        $mailer = (string) config('mail.default', 'log');
        $configured = $mailer === 'resend'
            ? filled(config('services.resend.key')) && filled(config('mail.from.address'))
            : filled(config('mail.from.address'));

        return $this->integration(
            id: 'transactional-email',
            name: 'Transactional email',
            category: 'Communications',
            provider: $mailer === 'resend' ? 'Resend' : str($mailer)->headline()->toString(),
            description: 'Account verification, invitation, password reset and workflow notifications.',
            status: $configured ? 'configured' : 'attention',
            mode: app()->environment('production') ? 'Production' : 'Development',
            configured: $configured,
            lastActivity: null,
            metrics: [
                ['label' => 'Active mailer', 'value' => $mailer],
                ['label' => 'Sender domain', 'value' => $this->emailDomain((string) config('mail.from.address'))],
            ],
            safeguards: ['API credentials are never returned by the API', 'Messages are processed through the queue'],
        );
    }

    /** @return array<string, mixed> */
    private function ocppIntegration(): array
    {
        $configured = filled(config('ocpp.gateway.shared_secret'));
        $timeout = max(1, (int) config('availability.communication_timeout_seconds', 90));
        $recentStations = Station::query()
            ->where('ocpp_version', 'OCPP 1.6J')
            ->whereNotNull('ocpp_auth_secret_hash')
            ->where(function ($query) use ($timeout): void {
                $query->where('last_heartbeat_at', '>=', now()->subSeconds($timeout))
                    ->orWhere('ocpp_last_message_at', '>=', now()->subSeconds($timeout));
            })
            ->count();
        $latestEvent = OcppEvent::query()->latest('received_at')->first();

        return $this->integration(
            id: 'ocpp-gateway',
            name: 'OCPP gateway',
            category: 'Charging network',
            provider: 'OCPP 1.6 JSON gateway',
            description: 'Signed ingestion of station events, heartbeats, transactions and remote commands.',
            status: ! $configured ? 'attention' : ($recentStations > 0 ? 'operational' : 'configured'),
            mode: app()->environment('production') ? 'Production' : 'Development',
            configured: $configured,
            lastActivity: $latestEvent?->received_at?->toISOString() ?? $latestEvent?->created_at?->toISOString(),
            metrics: [
                ['label' => 'Managed stations', 'value' => Station::query()->whereNotNull('ocpp_auth_secret_hash')->count()],
                ['label' => 'Recently connected', 'value' => $recentStations],
            ],
            safeguards: ['Gateway requests require a timestamped signature', 'Station authentication secrets are stored as hashes'],
        );
    }

    /** @return array<string, mixed> */
    private function paymentIntegration(): array
    {
        $driver = (string) config('payments.default', 'simulated');
        $configured = in_array($driver, ['simulated', 'wiremock'], true)
            && ($driver !== 'wiremock' || filled(config('payments.simulator.base_url')));
        $latestEvent = PaymentProviderEvent::query()->latest('received_at')->first();

        return $this->integration(
            id: 'payment-adapter',
            name: 'Payment adapter',
            category: 'Billing',
            provider: $driver === 'wiremock' ? 'WireMock simulator' : 'In-process simulator',
            description: 'Extensible payment port with idempotent operations and signed provider webhooks.',
            status: $configured ? 'configured' : 'attention',
            mode: 'Sandbox',
            configured: $configured,
            lastActivity: $latestEvent?->received_at?->toISOString() ?? $latestEvent?->created_at?->toISOString(),
            metrics: [
                ['label' => 'Active driver', 'value' => $driver],
                ['label' => 'Provider events', 'value' => PaymentProviderEvent::query()->count()],
            ],
            safeguards: ['Idempotency keys prevent duplicate charges', 'Provider webhooks require a verified signature'],
        );
    }

    /** @return array<string, mixed> */
    private function mappingIntegration(): array
    {
        return $this->integration(
            id: 'mapping',
            name: 'Station mapping',
            category: 'Geospatial',
            provider: 'Leaflet + OpenStreetMap',
            description: 'Interactive station discovery, coordinate selection and external route opening.',
            status: 'configured',
            mode: 'Public tiles',
            configured: true,
            lastActivity: null,
            metrics: [
                ['label' => 'Geocoded stations', 'value' => Station::query()->whereNotNull('latitude')->whereNotNull('longitude')->count()],
                ['label' => 'Tile provider', 'value' => 'OpenStreetMap'],
            ],
            safeguards: ['No map API secret is stored in the frontend', 'Coordinates are validated by the backend'],
        );
    }

    /** @param list<array{label: string, value: mixed}> $metrics
     * @param  list<string>  $safeguards
     * @return array<string, mixed>
     */
    private function integration(string $id, string $name, string $category, string $provider, string $description, string $status, string $mode, bool $configured, ?string $lastActivity, array $metrics, array $safeguards): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'provider' => $provider,
            'description' => $description,
            'status' => $status,
            'mode' => $mode,
            'configured' => $configured,
            'last_activity_at' => $lastActivity,
            'metrics' => $metrics,
            'safeguards' => $safeguards,
        ];
    }

    private function emailDomain(string $email): string
    {
        return str_contains($email, '@') ? str($email)->after('@')->toString() : 'Not set';
    }
}
