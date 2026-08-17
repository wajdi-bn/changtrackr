<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_documentation_is_restricted_outside_local_environment(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_super_admin_can_open_the_documentation_and_exported_contract_is_valid(): void
    {
        Role::query()->create([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);

        $superAdmin = User::factory()->create(['status' => 'active']);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::allows('viewApiDocs'));
        $this->assertNotNull(Route::getRoutes()->getByName('scramble.docs.ui'));
        $this->assertNotNull(Route::getRoutes()->getByName('scramble.docs.document'));

        $document = json_decode(
            file_get_contents(base_path('../docs/api/openapi.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame('ChargeTrackr API', $document['info']['title']);
        $this->assertSame('apiKey', $document['components']['securitySchemes']['sanctumSession']['type']);
        $this->assertSame('cookie', $document['components']['securitySchemes']['sanctumSession']['in']);
        $this->assertSame('laravel_session', $document['components']['securitySchemes']['sanctumSession']['name']);
        $this->assertSame([], $document['paths']['/auth/login']['post']['security']);
        $this->assertArrayNotHasKey('security', $document['paths']['/stations']['get']);
        $this->assertSame([
            [
                'url' => 'https://api.chargetrackr.me/api',
                'description' => 'Production',
            ],
            [
                'url' => 'http://localhost:8000/api',
                'description' => 'Local development',
            ],
        ], $document['servers']);

        $documentedTags = collect($document['tags'] ?? [])->keyBy('name');

        $this->assertSame([
            'Access & onboarding',
            'Account & profile',
            'Workspace',
            'Organizations & users',
            'Stations & OCPP',
            'Charging sessions',
            'Payments',
            'Pricing & subscriptions',
            'Operations & maintenance',
            'Reports & analytics',
            'Documents',
            'Platform administration',
        ], $documentedTags->keys()->all());

        foreach ($documentedTags as $tag) {
            $this->assertNotEmpty($tag['description'] ?? null, "Missing description for tag [{$tag['name']}].");
        }

        foreach (array_keys($document['paths']) as $path) {
            $this->assertFalse(
                str_starts_with($path, '/internal/'),
                "Internal machine endpoint [{$path}] must not be public documentation.",
            );
        }

        $operationIds = [];
        $documentedOperations = [];
        $publicOperations = [];

        foreach ($document['paths'] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }

                $operation = $pathItem[$method];
                $operationLabel = strtoupper($method).' '.$path;

                $this->assertNotEmpty(
                    $operation['summary'] ?? null,
                    "Missing summary for [{$method} {$path}].",
                );
                $this->assertNotEmpty(
                    $operation['tags'] ?? null,
                    "Missing functional group for [{$method} {$path}].",
                );

                foreach ($operation['tags'] as $tag) {
                    $this->assertNotSame('Other', $tag, "Unclassified operation [{$operationLabel}].");
                    $this->assertTrue($documentedTags->has($tag), "Unknown tag [{$tag}] on [{$operationLabel}].");
                }

                $operationId = $operation['operationId'] ?? null;
                $this->assertNotEmpty($operationId, "Missing operationId for [{$operationLabel}].");
                $this->assertArrayNotHasKey($operationId, $operationIds, "Duplicate operationId [{$operationId}].");
                $operationIds[$operationId] = $operationLabel;

                $this->assertNotEmpty($operation['responses'] ?? null, "Missing responses for [{$operationLabel}].");
                $this->assertTrue(
                    collect(array_keys($operation['responses']))->contains(
                        fn (string|int $status): bool => str_starts_with((string) $status, '2'),
                    ),
                    "Missing successful response for [{$operationLabel}].",
                );

                foreach ($operation['responses'] as $status => $response) {
                    if (isset($response['$ref'])) {
                        continue;
                    }

                    $this->assertNotEmpty(
                        $response['description'] ?? null,
                        "Missing response description for [{$operationLabel}] status [{$status}].",
                    );
                }

                $documentedOperations[strtoupper($method).' '.$this->normalizePath($path)] = true;

                if (($operation['security'] ?? null) === []) {
                    $publicOperations[] = $operationLabel;
                }
            }
        }

        sort($publicOperations);
        $expectedPublicOperations = [
            'GET /auth/session',
            'GET /public/commercial-plans',
            'POST /account-invitations/accept',
            'POST /account-invitations/inspect',
            'POST /auth/email/resend',
            'POST /auth/forgot-password',
            'POST /auth/login',
            'POST /auth/register',
            'POST /auth/reset-password',
            'POST /demo-requests',
        ];
        sort($expectedPublicOperations);

        $this->assertSame($expectedPublicOperations, $publicOperations);
        $this->assertTrue($document['paths']['/user']['get']['deprecated']);
        $this->assertSame('legacyUser.show', $document['paths']['/user']['get']['operationId']);

        $publicApiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
            ->reject(fn ($route): bool => str_starts_with($route->uri(), 'api/internal/'));

        foreach ($publicApiRoutes as $route) {
            $methods = collect($route->methods())
                ->intersect(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
            $normalizedPath = $this->normalizePath('/'.substr($route->uri(), 4));

            $this->assertTrue(
                $methods->contains(fn (string $method): bool => isset($documentedOperations[$method.' '.$normalizedPath])),
                "Laravel route [{$methods->implode('|')} {$normalizedPath}] is missing from OpenAPI.",
            );
        }

        foreach (array_keys($documentedOperations) as $documentedOperation) {
            [$method, $path] = explode(' ', $documentedOperation, 2);

            $this->assertTrue(
                $publicApiRoutes->contains(function ($route) use ($method, $path): bool {
                    $routePath = $this->normalizePath('/'.substr($route->uri(), 4));

                    return $routePath === $path && in_array($method, $route->methods(), true);
                }),
                "OpenAPI operation [{$documentedOperation}] has no matching Laravel route.",
            );
        }
    }

    private function normalizePath(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{parameter}', $path) ?? $path;
    }
}
