<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Tag;

class ConfigureOpenApiDocument implements DocumentTransformer
{
    /**
     * @var array<string, string>
     */
    private const TAGS = [
        'Access & onboarding' => 'Public access, authentication, account activation, invitations and onboarding.',
        'Account & profile' => 'Authenticated profile, preferences and account security.',
        'Workspace' => 'Role-scoped dashboards, search and operational notifications.',
        'Organizations & users' => 'Organizations, employees, customers, roles and tenant-scoped user management.',
        'Stations & OCPP' => 'Charging stations, connectors, commissioning, telemetry, supervision and simulation.',
        'Charging sessions' => 'Charging attempts, sessions, live lifecycle and remote stop operations.',
        'Payments' => 'Session payment authorization, settlement records, exports and receipts.',
        'Pricing & subscriptions' => 'Tariffs, charging plans, SaaS plans, subscriptions and organization billing.',
        'Operations & maintenance' => 'Alerts, interventions, maintenance plans, evidence and operational reports.',
        'Reports & analytics' => 'Role-specific analytics, internal report exchange, attachments and exports.',
        'Documents' => 'Protected document download and deletion operations.',
        'Platform administration' => 'Super Admin governance, integrations, permissions, audit and global settings.',
    ];

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->tags = array_map(
            fn (string $description, string $name): Tag => new Tag($name, $description),
            self::TAGS,
            array_keys(self::TAGS),
        );
    }
}
