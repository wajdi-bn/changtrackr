<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
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
        'reporting' => 'Reports & analytics',
        'search' => 'Workspace',
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

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $uri = $routeInfo->route->uri();
        $prefix = explode('/', Str::after($uri, 'api/'))[0];

        $operation->setTags([
            self::TAGS[$prefix] ?? 'Other',
        ]);

        if ($operation->summary !== '') {
            return;
        }

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
