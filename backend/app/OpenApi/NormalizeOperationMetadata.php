<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

class NormalizeOperationMetadata implements OperationTransformer
{
    /**
     * @var array<string, string>
     */
    private const TAGS = [
        'account-invitations' => 'Access & onboarding',
        'account-preferences' => 'Account & profile',
        'account-security' => 'Account & profile',
        'alerts' => 'Operations & maintenance',
        'asset-documents' => 'Documents',
        'auth' => 'Access & onboarding',
        'charging-attempts' => 'Charging sessions',
        'charging-plans' => 'Pricing & subscriptions',
        'charging-sessions' => 'Charging sessions',
        'commercial' => 'Pricing & subscriptions',
        'connector-qr' => 'Stations & OCPP',
        'customers' => 'Organizations & users',
        'dashboard' => 'Workspace',
        'demo-requests' => 'Access & onboarding',
        'internal-reports' => 'Reports & analytics',
        'interventions' => 'Operations & maintenance',
        'maintenances' => 'Operations & maintenance',
        'notification-preferences' => 'Account & profile',
        'notifications' => 'Workspace',
        'onboarding' => 'Access & onboarding',
        'organization-billing' => 'Pricing & subscriptions',
        'organizations' => 'Organizations & users',
        'payments' => 'Payments',
        'platform' => 'Platform administration',
        'pricing' => 'Pricing & subscriptions',
        'profile' => 'Account & profile',
        'public' => 'Pricing & subscriptions',
        'reporting' => 'Reports & analytics',
        'search' => 'Workspace',
        'simulation-lab' => 'Stations & OCPP',
        'stations' => 'Stations & OCPP',
        'subscription-invoices' => 'Pricing & subscriptions',
        'subscription-plans' => 'Pricing & subscriptions',
        'subscriptions' => 'Pricing & subscriptions',
        'tariff-assignments' => 'Pricing & subscriptions',
        'tariffs' => 'Pricing & subscriptions',
        'user' => 'Account & profile',
        'users' => 'Organizations & users',
    ];

    /**
     * @var array<string, string>
     */
    private const ACTION_SUMMARIES = [
        'accept' => 'Accept invitation',
        'cancel' => 'Cancel invitation',
        'catalog' => 'List available plans',
        'changePassword' => 'Change account password',
        'content' => 'Download document content',
        'effective' => 'Get effective station pricing',
        'export' => 'Export records',
        'invoiceDocument' => 'Download invoice document',
        'issueInvitation' => 'Issue administrator invitation',
        'login' => 'Sign in',
        'logout' => 'Sign out',
        'map' => 'List stations for the map',
        'me' => 'Get authenticated user',
        'portfolio' => 'View commercial portfolio',
        'remoteStop' => 'Stop charging session remotely',
        'remind' => 'Send invitation reminder',
        'renew' => 'Renew invitation',
        'resend' => 'Resend verification email',
        'reset' => 'Reset password',
        'rotateCredentials' => 'Rotate station credentials',
        'sendLink' => 'Send password reset link',
        'session' => 'Check authentication session',
        'settle' => 'Settle commercial invoice',
        'stop' => 'Stop charging session',
        'subscribers' => 'List plan subscribers',
        'suspend' => 'Suspend commercial subscription',
        'telemetry' => 'View station telemetry',
        'unlock' => 'Unlock station connector',
        'void' => 'Void commercial invoice',
    ];

    /**
     * @var array<string, array{summary: string, tag?: string, description?: string, operation_id?: string, deprecated?: bool}>
     */
    private const ROUTE_METADATA = [
        'GET api/public/commercial-plans' => [
            'summary' => 'List public commercial plans',
            'tag' => 'Pricing & subscriptions',
        ],
        'PUT api/onboarding/organization' => ['summary' => 'Update onboarding organization'],
        'POST api/onboarding/organization-logo' => ['summary' => 'Upload onboarding organization logo'],
        'POST api/demo-requests/{demoRequest}/start-review' => ['summary' => 'Start demo request review'],
        'POST api/demo-requests/{demoRequest}/invitation/revoke' => ['summary' => 'Revoke administrator invitation'],
        'POST api/profile/avatar' => ['summary' => 'Upload profile photo'],
        'DELETE api/profile/avatar' => ['summary' => 'Remove profile photo'],
        'GET api/user' => [
            'summary' => 'Get authenticated user (legacy)',
            'operation_id' => 'legacyUser.show',
            'description' => 'Legacy alias kept for compatibility. New integrations should use `GET /auth/me`.',
            'deprecated' => true,
        ],
        'POST api/charging-sessions/{chargingSession}/payments' => [
            'summary' => 'Authorize charging session payment',
            'tag' => 'Payments',
        ],
        'GET api/alerts/{alert}/report' => ['summary' => 'Download alert report'],
        'POST api/interventions/{intervention}/notes' => ['summary' => 'Add intervention note'],
        'GET api/interventions/{intervention}/documents' => ['summary' => 'List intervention documents'],
        'POST api/interventions/{intervention}/documents' => ['summary' => 'Upload intervention document'],
        'GET api/interventions/{intervention}/document' => ['summary' => 'Download intervention report'],
        'POST api/interventions/{intervention}/photos' => ['summary' => 'Upload intervention photo'],
        'DELETE api/interventions/{intervention}/photos/{photo}' => ['summary' => 'Delete intervention photo'],
        'GET api/maintenances/{maintenance}/report' => ['summary' => 'Download maintenance report'],
        'POST api/organizations/bulk-status' => ['summary' => 'Update organization statuses in bulk'],
        'GET api/platform/integrations' => ['summary' => 'List platform integrations'],
        'GET api/commercial/plans' => ['summary' => 'List SaaS plans'],
        'POST api/commercial/plans' => ['summary' => 'Create SaaS plan'],
        'PATCH api/commercial/plans/{saasPlan}' => ['summary' => 'Update SaaS plan'],
        'POST api/commercial/subscriptions/{subscription}/extend-trial' => ['summary' => 'Extend organization trial'],
        'POST api/commercial/subscriptions/{subscription}/restore' => ['summary' => 'Restore commercial subscription'],
        'GET api/organization-billing' => ['summary' => 'Get organization subscription and billing'],
        'POST api/organization-billing/requests' => ['summary' => 'Request an organization plan change'],
        'GET api/subscription-invoices' => ['summary' => 'List subscription invoices'],
        'POST api/subscriptions/{subscription}/retry-payment' => ['summary' => 'Retry subscription payment'],
        'GET api/internal-reports/recipients' => ['summary' => 'List report recipients'],
        'GET api/internal-reports/{internalReport}/document' => ['summary' => 'Download internal report'],
        'GET api/internal-reports/{internalReport}/attachments' => ['summary' => 'List report attachments'],
        'POST api/internal-reports/{internalReport}/attachments' => ['summary' => 'Upload report attachment'],
        'GET api/connector-qr/{token}' => ['summary' => 'Resolve connector QR code'],
        'GET api/stations/commissioning/profiles' => ['summary' => 'List commissioning profiles'],
        'POST api/stations/{station}/commissioning/retry' => ['summary' => 'Retry station commissioning'],
        'GET api/stations/{station}/documents' => ['summary' => 'List station documents'],
        'POST api/stations/{station}/documents' => ['summary' => 'Upload station document'],
        'GET api/stations/{station}/commands' => ['summary' => 'List station command history'],
        'POST api/stations/{station}/commands/reset' => ['summary' => 'Reset station'],
        'PUT api/stations/{station}/maintenance' => ['summary' => 'Change station maintenance mode'],
        'GET api/simulation-lab/stations' => [
            'summary' => 'List simulated stations',
            'tag' => 'Stations & OCPP',
        ],
        'POST api/stations/{station}/simulator/actions' => ['summary' => 'Submit simulator action'],
        'POST api/stations/{station}/connectors/{connector}/charging-terminal/actions' => ['summary' => 'Submit client terminal action'],
        'GET api/stations/{station}/connectors/{connector}/charging-terminal/actions/{action}' => ['summary' => 'Get client terminal action status'],
        'PATCH api/notifications/{userNotification}/read' => ['summary' => 'Mark notification as read'],
        'POST api/notifications/read-all' => ['summary' => 'Mark all notifications as read'],
        'POST api/notifications/read-context' => ['summary' => 'Mark contextual notifications as read'],
    ];

    /**
     * @var array<string, string>
     */
    private const RESPONSE_DESCRIPTIONS = [
        '200' => 'Successful response.',
        '201' => 'Resource created.',
        '202' => 'Request accepted for processing.',
        '204' => 'No content.',
        '400' => 'Bad request.',
        '401' => 'Authentication required.',
        '403' => 'Operation forbidden.',
        '404' => 'Resource not found.',
        '409' => 'Business conflict.',
        '419' => 'CSRF token or session expired.',
        '422' => 'Validation failed.',
        '429' => 'Rate limit exceeded.',
        '500' => 'Unexpected server error.',
    ];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $uri = $routeInfo->route->uri();
        $prefix = explode('/', Str::after($uri, 'api/'))[0];
        $metadata = self::ROUTE_METADATA[strtoupper($operation->method).' '.$uri] ?? [];

        $operation->setTags([
            $metadata['tag'] ?? self::TAGS[$prefix] ?? 'Other',
        ]);

        if (isset($metadata['summary'])) {
            $operation->summary($metadata['summary']);
        } elseif ($operation->summary === '') {
            $method = $routeInfo->methodName();
            $subject = $this->subject($routeInfo);

            $operation->summary(match ($method) {
                'index' => 'List '.Str::plural($subject),
                'show' => 'View '.$subject,
                'store' => 'Create '.$subject,
                'update' => 'Update '.$subject,
                'destroy' => 'Delete '.$subject,
                '__invoke' => 'View '.$subject,
                default => self::ACTION_SUMMARIES[$method] ?? Str::headline((string) $method).' '.$subject,
            });
        }

        if (isset($metadata['description'])) {
            $operation->description($metadata['description']);
        }

        if (isset($metadata['operation_id'])) {
            $operation->setOperationId($metadata['operation_id']);
        }

        if ($metadata['deprecated'] ?? false) {
            $operation->deprecated = true;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || trim($response->description) !== '') {
                continue;
            }

            $response->setDescription(
                self::RESPONSE_DESCRIPTIONS[(string) $response->code] ?? 'API response.',
            );
        }
    }

    private function subject(RouteInfo $routeInfo): string
    {
        $className = $routeInfo->className();

        if (! $className) {
            return 'resource';
        }

        return Str::of(class_basename($className))
            ->beforeLast('Controller')
            ->headline()
            ->lower()
            ->toString();
    }
}
