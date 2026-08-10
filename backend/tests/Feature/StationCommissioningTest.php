<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppStationAuthenticationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StationCommissioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_commissions_an_external_station_and_receives_the_secret_only_once(): void
    {
        [$admin, $organization] = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/stations/commission', $this->payload('CT-COMMISSION-001', 'external'))
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.connectors_count', 2)
            ->assertJsonPath('data.ocpp_commissioning_target', 'external')
            ->assertJsonPath('data.commissioning_status', 'awaiting_connection')
            ->assertJsonPath('commissioning.target', 'external')
            ->assertJsonPath('commissioning.identity', 'CT-COMMISSION-001')
            ->assertJsonPath('commissioning.secret_visible_once', true);

        $station = Station::query()->where('reference', 'CT-COMMISSION-001')->firstOrFail();
        $secret = (string) $response->json('commissioning.secret');

        $this->assertCount(2, $station->connectors);
        $this->assertNotSame($secret, $station->ocpp_auth_secret_hash);
        $this->assertTrue(Hash::check($secret, $station->ocpp_auth_secret_hash));
        $this->assertNotNull(app(OcppStationAuthenticationService::class)->authenticate(
            'CT-COMMISSION-001',
            'CT-COMMISSION-001',
            $secret,
        ));

        $this->getJson("/api/stations/{$station->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.ocpp_auth_secret_hash')
            ->assertJsonMissingPath('data.secret')
            ->assertJsonPath('data.ocpp_secret_configured', true);
    }

    public function test_commissioning_is_atomic_and_rejects_tenant_injection_and_invalid_connectors(): void
    {
        [$admin] = $this->userWithRole('admin');
        $otherOrganization = Organization::query()->create([
            'name' => 'Other Network',
            'slug' => 'other-network',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/stations/commission', [
            ...$this->payload('CT-COMMISSION-002', 'simulator'),
            'organization_id' => $otherOrganization->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $invalid = $this->payload('CT-COMMISSION-003', 'simulator');
        $invalid['connectors'][1]['ocpp_connector_id'] = 3;
        $this->postJson('/api/stations/commission', $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('connectors');

        $this->assertDatabaseMissing('stations', ['reference' => 'CT-COMMISSION-003']);
    }

    public function test_simulator_station_returns_a_local_command_without_exposing_a_secret(): void
    {
        [$operator] = $this->userWithRole('operator');
        Sanctum::actingAs($operator);

        $this->postJson('/api/stations/commission', $this->payload('CT-SIM-101', 'simulator'))
            ->assertCreated()
            ->assertJsonPath('data.ocpp_commissioning_target', 'simulator')
            ->assertJsonPath('data.commissioning_status', 'not_provisioned')
            ->assertJsonPath('commissioning.secret', null)
            ->assertJsonPath('commissioning.secret_visible_once', false)
            ->assertJsonPath('commissioning.simulator_command', 'npm run ocpp:add-simulator-station -- CT-SIM-101');
    }

    public function test_rotating_external_credentials_invalidates_the_previous_secret(): void
    {
        [$admin] = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/stations/commission', $this->payload('CT-ROTATE-001', 'external'))
            ->assertCreated();
        $stationId = (int) $created->json('data.id');
        $oldSecret = (string) $created->json('commissioning.secret');

        $rotated = $this->postJson("/api/stations/{$stationId}/commissioning/rotate-credentials")
            ->assertOk()
            ->assertJsonPath('commissioning.secret_visible_once', true);
        $newSecret = (string) $rotated->json('commissioning.secret');
        $authenticator = app(OcppStationAuthenticationService::class);

        $this->assertNotSame($oldSecret, $newSecret);
        $this->assertNull($authenticator->authenticate('CT-ROTATE-001', 'CT-ROTATE-001', $oldSecret));
        $this->assertNotNull($authenticator->authenticate('CT-ROTATE-001', 'CT-ROTATE-001', $newSecret));
    }

    public function test_local_command_registers_station_and_connector_powers_in_simulator_manifest(): void
    {
        [, $organization] = $this->userWithRole('operator');
        $station = Station::query()->create([
            ...collect($this->payload('CT-SIM-CMD-001', 'simulator'))->except(['commissioning_target', 'connectors'])->all(),
            'organization_id' => $organization->id,
            'status' => 'offline',
        ]);
        foreach ($this->payload('CT-SIM-CMD-001', 'simulator')['connectors'] as $connector) {
            $station->connectors()->create([...$connector, 'status' => 'offline']);
        }

        config()->set('ocpp.simulator.station_secret', str_repeat('s', 40));
        $manifestPath = storage_path('framework/testing/ocpp-stations.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, "[]\n");

        try {
            $this->artisan('ocpp:register-simulator-station', [
                'station' => $station->reference,
                '--manifest' => $manifestPath,
            ])->assertSuccessful();

            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('CT-SIM-CMD-001', $manifest[0]['identity']);
            $this->assertSame([120, 22], $manifest[0]['connectorPowersKw']);
            $this->assertSame('simulator', $station->fresh()->ocpp_commissioning_target);
            $this->assertTrue(Hash::check(str_repeat('s', 40), $station->fresh()->ocpp_auth_secret_hash));
        } finally {
            File::delete($manifestPath);
        }
    }

    /** @return array{User, Organization} */
    private function userWithRole(string $role): array
    {
        $organization = Organization::query()->create([
            'name' => ucfirst($role).' Organization',
            'slug' => $role.'-'.uniqid(),
            'status' => 'active',
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $user->assignRole($role);

        return [$user, $organization];
    }

    /** @return array<string, mixed> */
    private function payload(string $reference, string $target): array
    {
        return [
            'name' => 'Commissioned Station',
            'reference' => $reference,
            'ocpp_identity' => $reference,
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Rue du Lac',
            'latitude' => 36.832,
            'longitude' => 10.235,
            'max_power_kw' => 120,
            'model' => 'Terra 124',
            'manufacturer' => 'ABB',
            'ocpp_version' => 'OCPP 1.6J',
            'model_image' => '/assets/stations/models/terra-hp-150.webp',
            'commissioning_target' => $target,
            'connectors' => [
                [
                    'external_id' => 'A1',
                    'ocpp_connector_id' => 1,
                    'type' => 'CCS2',
                    'current_type' => 'DC',
                    'max_power_kw' => 120,
                ],
                [
                    'external_id' => 'A2',
                    'ocpp_connector_id' => 2,
                    'type' => 'Type 2',
                    'current_type' => 'AC',
                    'max_power_kw' => 22,
                ],
            ],
        ];
    }
}
