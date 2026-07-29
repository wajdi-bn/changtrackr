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

        foreach (array_keys($document['paths']) as $path) {
            $this->assertFalse(
                str_starts_with($path, '/internal/'),
                "Internal machine endpoint [{$path}] must not be public documentation.",
            );
        }

        foreach ($document['paths'] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }

                $this->assertNotEmpty(
                    $pathItem[$method]['summary'] ?? null,
                    "Missing summary for [{$method} {$path}].",
                );
                $this->assertNotEmpty(
                    $pathItem[$method]['tags'] ?? null,
                    "Missing functional group for [{$method} {$path}].",
                );
            }
        }
    }
}
