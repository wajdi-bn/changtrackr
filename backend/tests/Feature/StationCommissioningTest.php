<?php

namespace Tests\Feature;

use App\Jobs\ProvisionOcppSimulatorStation;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppSimulatorControlClient;
use App\Services\Ocpp\OcppStationAuthenticationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class StationCommissioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('ocpp.simulator.control_url', 'http://simulator-control:8081');
        config()->set('ocpp.simulator.control_token', 'private-control-token');
        config()->set('ocpp.simulator.station_secret', str_repeat('s', 40));
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
        Queue::fake();
        Http::fake(['http://simulator-control:8081/profiles' => Http::response(['data' => $this->simulatorProfiles()])]);
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

        $invalid = $this->payload('CT-COMMISSION-003', 'external');
        $invalid['connectors'][1]['current_type'] = 'invalid';
        $this->postJson('/api/stations/commission', $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('connectors.1.current_type');

        $this->assertDatabaseMissing('stations', ['reference' => 'CT-COMMISSION-003']);
    }

    public function test_simulator_station_uses_a_server_profile_and_queues_provisioning_without_a_shell_command(): void
    {
        Queue::fake();
        Http::fake(['http://simulator-control:8081/profiles' => Http::response(['data' => $this->simulatorProfiles()])]);
        [$operator] = $this->userWithRole('operator');
        Sanctum::actingAs($operator);

        $this->postJson('/api/stations/commission', $this->payload('CT-SIM-101', 'simulator'))
            ->assertCreated()
            ->assertJsonPath('data.ocpp_commissioning_target', 'simulator')
            ->assertJsonPath('data.ocpp_simulator_profile', 'dc_fast_dual')
            ->assertJsonPath('data.commissioning_status', 'provisioning')
            ->assertJsonPath('data.connectors.0.type', 'CCS2')
            ->assertJsonPath('data.connectors.1.type', 'CHAdeMO')
            ->assertJsonPath('commissioning.secret', null)
            ->assertJsonPath('commissioning.secret_visible_once', false)
            ->assertJsonPath('commissioning.simulator_profile', 'dc_fast_dual')
            ->assertJsonPath('commissioning.provisioning_status', 'queued')
            ->assertJsonMissingPath('commissioning.simulator_command');

        $station = Station::query()->where('reference', 'CT-SIM-101')->firstOrFail();
        $this->assertTrue(Hash::check(str_repeat('s', 40), $station->ocpp_auth_secret_hash));
        Queue::assertPushed(ProvisionOcppSimulatorStation::class, fn ($job): bool => $job->stationId === $station->id);
    }

    public function test_authorized_user_can_read_the_safe_simulator_profile_catalog(): void
    {
        Http::fake(['http://simulator-control:8081/profiles' => Http::response(['data' => $this->simulatorProfiles()])]);
        [$operator] = $this->userWithRole('operator');
        Sanctum::actingAs($operator);

        $this->getJson('/api/stations/commissioning/profiles')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'dc_fast_dual')
            ->assertJsonMissingPath('data.0.template');
    }

    public function test_provisioning_job_registers_the_station_and_records_success(): void
    {
        Queue::fake();
        Http::fake([
            'http://simulator-control:8081/profiles' => Http::response(['data' => $this->simulatorProfiles()]),
            'http://simulator-control:8081/stations' => Http::response([
                'data' => ['identity' => 'CT-SIM-JOB-001', 'started' => true, 'connected' => true],
            ], 201),
        ]);
        [$operator] = $this->userWithRole('operator');
        Sanctum::actingAs($operator);
        $stationId = (int) $this->postJson('/api/stations/commission', $this->payload('CT-SIM-JOB-001', 'simulator'))
            ->assertCreated()
            ->json('data.id');

        (new ProvisionOcppSimulatorStation($stationId))->handle(app(OcppSimulatorControlClient::class));

        $station = Station::query()->findOrFail($stationId);
        $this->assertSame('provisioned', $station->ocpp_provisioning_status);
        $this->assertNotNull($station->ocpp_provisioned_at);
        $this->assertNull($station->ocpp_provisioning_error);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://simulator-control:8081/stations'
            && $request['identity'] === 'CT-SIM-JOB-001'
            && $request['profile'] === 'dc_fast_dual');
    }

    public function test_failed_simulator_provisioning_can_be_retried_without_exposing_internal_errors(): void
    {
        Queue::fake();
        Http::fake(['http://simulator-control:8081/profiles' => Http::response(['data' => $this->simulatorProfiles()])]);
        [$admin] = $this->userWithRole('admin');
        Sanctum::actingAs($admin);
        $stationId = (int) $this->postJson('/api/stations/commission', $this->payload('CT-SIM-RETRY-001', 'simulator'))
            ->assertCreated()
            ->json('data.id');

        $job = new ProvisionOcppSimulatorStation($stationId);
        $job->failed(new RuntimeException('private simulator details'));
        $station = Station::query()->findOrFail($stationId);
        $this->assertSame('failed', $station->ocpp_provisioning_status);
        $this->assertStringNotContainsString('private simulator details', $station->ocpp_provisioning_error);

        $this->postJson("/api/stations/{$stationId}/commissioning/retry")
            ->assertAccepted()
            ->assertJsonPath('data.commissioning_status', 'provisioning')
            ->assertJsonPath('commissioning.provisioning_status', 'queued');
        Queue::assertPushed(ProvisionOcppSimulatorStation::class, fn ($queued): bool => $queued->stationId === $stationId);
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
        $payload = $this->payload('CT-SIM-CMD-001', 'external');
        $station = Station::query()->create([
            ...collect($payload)->except(['commissioning_target', 'connectors'])->all(),
            'organization_id' => $organization->id,
            'status' => 'offline',
            'ocpp_commissioning_target' => 'simulator',
            'ocpp_simulator_profile' => 'dc_fast_dual',
            'ocpp_provisioning_status' => 'not_provisioned',
        ]);
        foreach ($payload['connectors'] as $connector) {
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
        $base = [
            'name' => 'Commissioned Station',
            'reference' => $reference,
            'ocpp_identity' => $reference,
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Rue du Lac',
            'latitude' => 36.832,
            'longitude' => 10.235,
            'commissioning_target' => $target,
        ];

        if ($target === 'simulator') {
            return [...$base, 'simulator_profile' => 'dc_fast_dual'];
        }

        return [
            ...$base,
            'max_power_kw' => 120,
            'model' => 'Terra 124',
            'manufacturer' => 'ABB',
            'ocpp_version' => 'OCPP 1.6J',
            'model_image' => '/assets/stations/models/terra-hp-150.webp',
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

    /** @return list<array<string, mixed>> */
    private function simulatorProfiles(): array
    {
        return [[
            'key' => 'dc_fast_dual',
            'label' => 'Dual fast charger',
            'description' => 'One shared DC power cabinet with two connector standards.',
            'manufacturer' => 'ChargeTrackr Labs',
            'model' => 'Dual DC 150',
            'max_power_kw' => 150,
            'model_image' => '/assets/stations/models/evbox-troniq.webp',
            'connectors' => [
                ['external_id' => 'A1', 'ocpp_connector_id' => 1, 'type' => 'CCS2', 'current_type' => 'DC', 'max_power_kw' => 150],
                ['external_id' => 'A2', 'ocpp_connector_id' => 2, 'type' => 'CHAdeMO', 'current_type' => 'DC', 'max_power_kw' => 150],
            ],
        ]];
    }
}
