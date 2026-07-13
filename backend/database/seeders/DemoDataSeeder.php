<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\ChargingSession;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\TariffAssignment;
use App\Models\User;
use App\Services\TariffResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed local demo data.
     */
    public function run(): void
    {
        $organization = Organization::firstOrCreate(
            ['slug' => 'tunis-network-ops'],
            [
                'name' => 'Tunis Network Ops',
                'contact_email' => 'ops@chargetrackr.local',
                'contact_phone' => '+216 00 000 000',
                'status' => 'active',
            ],
        );
        $sahelOrganization = Organization::firstOrCreate(
            ['slug' => 'sahel-charge-network'],
            [
                'name' => 'Sahel Charge Network',
                'contact_email' => 'operations@sahelcharge.local',
                'contact_phone' => '+216 73 000 000',
                'status' => 'active',
            ],
        );

        $users = [
            ['name' => 'Meriem Haddad', 'email' => 'superadmin@chargetrackr.local', 'role' => 'super_admin', 'organization_id' => null, 'phone' => '+216 20 100 100', 'team' => 'Platform Administration', 'address' => 'Tunis, Tunisia'],
            ['name' => 'Sami Ben Amor', 'email' => 'admin@chargetrackr.local', 'role' => 'admin', 'organization_id' => $organization->id, 'phone' => '+216 20 200 200', 'team' => 'Management', 'address' => 'Les Berges du Lac, Tunis', 'avatar_url' => '/assets/avatar-vendor-1.jpg'],
            ['name' => 'Meriem Haddad', 'email' => 'operator@chargetrackr.local', 'role' => 'operator', 'organization_id' => $organization->id, 'phone' => '+216 20 300 300', 'team' => 'Network Operations', 'address' => 'La Marsa, Tunis'],
            ['name' => 'Nour Trabelsi', 'email' => 'technician@chargetrackr.local', 'role' => 'technician', 'organization_id' => $organization->id, 'phone' => '+216 20 400 400', 'team' => 'Field Maintenance', 'address' => 'Ariana, Tunisia'],
            ['name' => 'Karim Ben Salem', 'email' => 'technician2@chargetrackr.local', 'role' => 'technician', 'organization_id' => $organization->id, 'phone' => '+216 20 500 500', 'team' => 'Field Maintenance', 'address' => 'Sfax, Tunisia'],
            ['name' => 'Yasmine B.', 'email' => 'client@chargetrackr.local', 'role' => 'client', 'organization_id' => null, 'phone' => '+216 20 600 600', 'team' => null, 'address' => 'Nabeul, Tunisia'],
            ['name' => 'Leila Gharbi', 'email' => 'admin@sahelcharge.local', 'role' => 'admin', 'organization_id' => $sahelOrganization->id, 'phone' => '+216 21 700 700', 'team' => 'Management', 'address' => 'Hammamet, Tunisia'],
            ['name' => 'Hatem Mansour', 'email' => 'operator@sahelcharge.local', 'role' => 'operator', 'organization_id' => $sahelOrganization->id, 'phone' => '+216 21 800 800', 'team' => 'Network Operations', 'address' => 'Sousse, Tunisia'],
        ];

        foreach ($users as $userData) {
            $role = $userData['role'];
            unset($userData['role']);

            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    ...$userData,
                    'password' => Hash::make('password'),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role]);
        }

        $stations = [
            ['name' => 'Lac 1 Fast Hub', 'reference' => 'CT-TUN-001', 'location_name' => 'Lac 1', 'city' => 'Tunis', 'address' => 'Rue du Lac Biwa, Les Berges du Lac 1', 'latitude' => 36.8338, 'longitude' => 10.2370, 'status' => 'available', 'max_power_kw' => 150, 'model' => 'Terra HP 150', 'manufacturer' => 'ABB', 'ocpp_version' => 'OCPP 1.6J', 'model_image' => '/assets/charger-terra-hp-150.png', 'uptime_percent' => 99.4, 'energy_today_kwh' => 428, 'sessions_today' => 34, 'utilization_percent' => 72, 'revenue_today' => 612, 'open_alerts_count' => 0],
            ['name' => 'La Marsa Coast Station', 'reference' => 'CT-TUN-014', 'location_name' => 'La Marsa', 'city' => 'Tunis', 'address' => 'Avenue Habib Bourguiba, La Marsa', 'latitude' => 36.8782, 'longitude' => 10.3247, 'status' => 'charging', 'max_power_kw' => 120, 'model' => 'EVBox Troniq', 'manufacturer' => 'EVBox', 'ocpp_version' => 'OCPP 2.0.1', 'model_image' => '/assets/charger-evbox-troniq.png', 'uptime_percent' => 98.8, 'energy_today_kwh' => 313, 'sessions_today' => 22, 'utilization_percent' => 81, 'revenue_today' => 456, 'open_alerts_count' => 1],
            ['name' => 'Ariana Tech Park', 'reference' => 'CT-ARI-006', 'location_name' => 'Centre Urbain Nord', 'city' => 'Ariana', 'address' => 'Centre Urbain Nord, Ariana', 'latitude' => 36.8532, 'longitude' => 10.2037, 'status' => 'faulted', 'max_power_kw' => 60, 'model' => 'eNext Park DC', 'manufacturer' => 'Circontrol', 'ocpp_version' => 'OCPP 1.6J', 'model_image' => '/assets/charger-enext-park-dc.png', 'uptime_percent' => 94.1, 'energy_today_kwh' => 96, 'sessions_today' => 8, 'utilization_percent' => 34, 'revenue_today' => 141, 'open_alerts_count' => 3],
            ['name' => 'Sousse Marina Charger', 'reference' => 'CT-SOU-022', 'location_name' => 'Port El Kantaoui', 'city' => 'Sousse', 'address' => 'Port El Kantaoui tourist zone', 'latitude' => 35.8925, 'longitude' => 10.5943, 'status' => 'available', 'max_power_kw' => 100, 'model' => 'Raption 100', 'manufacturer' => 'Circontrol', 'ocpp_version' => 'OCPP 2.0.1', 'model_image' => '/assets/charger-raption-100.png', 'uptime_percent' => 99.1, 'energy_today_kwh' => 285, 'sessions_today' => 19, 'utilization_percent' => 63, 'revenue_today' => 398, 'open_alerts_count' => 0],
            ['name' => 'Sfax Industrial Zone', 'reference' => 'CT-SFX-017', 'location_name' => 'Route de Gabes', 'city' => 'Sfax', 'address' => 'Zone industrielle Poudriere II', 'latitude' => 34.7406, 'longitude' => 10.7603, 'status' => 'offline', 'max_power_kw' => 75, 'model' => 'Delta UFC 100', 'manufacturer' => 'Delta', 'ocpp_version' => 'OCPP 1.6J', 'model_image' => '/assets/charger-delta-ufc-100.png', 'uptime_percent' => 91.6, 'energy_today_kwh' => 0, 'sessions_today' => 0, 'utilization_percent' => 0, 'revenue_today' => 0, 'open_alerts_count' => 2],
            ['name' => 'Bizerte Port Charger', 'reference' => 'CT-BIZ-009', 'location_name' => 'Port de Bizerte', 'city' => 'Bizerte', 'address' => "Avenue de l'Environnement, Bizerte", 'latitude' => 37.2744, 'longitude' => 9.8739, 'status' => 'maintenance', 'max_power_kw' => 50, 'model' => 'Tritium RTM50', 'manufacturer' => 'Tritium', 'ocpp_version' => 'OCPP 1.6J', 'model_image' => '/assets/charger-tritium-rtm50.png', 'uptime_percent' => 96.8, 'energy_today_kwh' => 42, 'sessions_today' => 3, 'utilization_percent' => 18, 'revenue_today' => 64, 'open_alerts_count' => 1],
            ['name' => 'Nabeul City Center', 'reference' => 'CT-NAB-004', 'location_name' => 'Centre-ville', 'city' => 'Nabeul', 'address' => 'Avenue Habib Thameur, Nabeul', 'latitude' => 36.4513, 'longitude' => 10.7352, 'status' => 'available', 'max_power_kw' => 80, 'model' => 'SICHARGE D', 'manufacturer' => 'Siemens', 'ocpp_version' => 'OCPP 2.0.1', 'model_image' => '/assets/charger-sicharge-d.png', 'uptime_percent' => 98.2, 'energy_today_kwh' => 214, 'sessions_today' => 16, 'utilization_percent' => 58, 'revenue_today' => 301, 'open_alerts_count' => 0],
            ['name' => 'Monastir Airport EV', 'reference' => 'CT-MON-012', 'location_name' => 'Monastir Habib Bourguiba Airport', 'city' => 'Monastir', 'address' => 'Airport parking, Monastir', 'latitude' => 35.7581, 'longitude' => 10.7547, 'status' => 'charging', 'max_power_kw' => 120, 'model' => 'PowerDot DC 120', 'manufacturer' => 'PowerDot', 'ocpp_version' => 'OCPP 2.0.1', 'model_image' => '/assets/charger-powerdot-dc-120.png', 'uptime_percent' => 98.9, 'energy_today_kwh' => 344, 'sessions_today' => 27, 'utilization_percent' => 76, 'revenue_today' => 492, 'open_alerts_count' => 1],
            ['name' => 'Hammamet Seafront Hub', 'reference' => 'CT-HAM-031', 'organization_id' => $sahelOrganization->id, 'location_name' => 'Yasmine Hammamet', 'city' => 'Hammamet', 'address' => 'Marina promenade, Yasmine Hammamet', 'latitude' => 36.3740, 'longitude' => 10.5460, 'status' => 'available', 'max_power_kw' => 120, 'model' => 'Terra HP 150', 'manufacturer' => 'ABB', 'ocpp_version' => 'OCPP 1.6J', 'model_image' => '/assets/charger-terra-hp-150.png', 'uptime_percent' => 99.2, 'energy_today_kwh' => 238, 'sessions_today' => 17, 'utilization_percent' => 61, 'revenue_today' => 337, 'open_alerts_count' => 0],
        ];

        foreach ($stations as $index => $stationData) {
            $stationOrganizationId = $stationData['organization_id'] ?? $organization->id;
            unset($stationData['organization_id']);
            $station = Station::updateOrCreate(
                ['reference' => $stationData['reference']],
                [...$stationData, 'organization_id' => $stationOrganizationId, 'last_heartbeat_at' => now()->subSeconds(($index + 1) * 12)],
            );

            $connectorCount = [6, 4, 3, 5, 4, 2, 4, 5, 4][$index];
            for ($connectorIndex = 1; $connectorIndex <= $connectorCount; $connectorIndex++) {
                $status = $station->status === 'available' && $connectorIndex > 1 ? 'available' : $station->status;
                $station->connectors()->updateOrCreate(
                    ['external_id' => chr(64 + $index + 1).$connectorIndex],
                    [
                        'type' => $connectorIndex % 3 === 0 ? 'Type 2' : ($connectorIndex % 2 === 0 ? 'CHAdeMO' : 'CCS2'),
                        'current_type' => $connectorIndex % 3 === 0 ? 'AC' : 'DC',
                        'max_power_kw' => $connectorIndex % 3 === 0 ? 22 : $station->max_power_kw,
                        'status' => $status,
                        'last_status_at' => now()->subMinutes($connectorIndex * 2),
                    ],
                );
            }
        }

        $standardTariff = Tariff::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => 'STANDARD'],
            [
                'name' => 'Standard charging',
                'description' => 'Default organization tariff for everyday charging.',
                'status' => 'active',
                'currency' => 'TND',
                'price_per_kwh_millimes' => 850,
                'session_fee_millimes' => 500,
                'idle_fee_per_minute_millimes' => 100,
                'minimum_charge_millimes' => 1000,
                'is_default' => true,
            ],
        );
        Tariff::updateOrCreate(
            ['organization_id' => $sahelOrganization->id, 'code' => 'SAHEL-STANDARD'],
            [
                'name' => 'Sahel public charging',
                'description' => 'Default pay-as-you-go tariff for Sahel Charge Network.',
                'status' => 'active',
                'currency' => 'TND',
                'price_per_kwh_millimes' => 920,
                'session_fee_millimes' => 400,
                'idle_fee_per_minute_millimes' => 100,
                'minimum_charge_millimes' => 1000,
                'is_default' => true,
            ],
        );
        $fastTariff = Tariff::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => 'FAST-DC'],
            [
                'name' => 'Fast DC premium',
                'description' => 'Premium rate for high-power hubs.',
                'status' => 'active',
                'currency' => 'TND',
                'price_per_kwh_millimes' => 1100,
                'session_fee_millimes' => 750,
                'idle_fee_per_minute_millimes' => 150,
                'minimum_charge_millimes' => 1500,
                'is_default' => false,
            ],
        );
        $nightTariff = Tariff::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => 'AC-NIGHT'],
            [
                'name' => 'AC night saver',
                'description' => 'Lower AC rate prepared for off-peak operation.',
                'status' => 'draft',
                'currency' => 'TND',
                'price_per_kwh_millimes' => 450,
                'session_fee_millimes' => 300,
                'idle_fee_per_minute_millimes' => 50,
                'minimum_charge_millimes' => 750,
                'is_default' => false,
            ],
        );

        $this->call(ChargingPlanSeeder::class);

        $fastStation = Station::query()->where('reference', 'CT-TUN-014')->firstOrFail();
        TariffAssignment::query()->updateOrCreate(
            ['station_id' => $fastStation->id],
            ['tariff_id' => $fastTariff->id, 'connector_id' => null],
        );
        $acConnector = Station::query()->where('reference', 'CT-TUN-001')->firstOrFail()->connectors()->where('type', 'Type 2')->first();
        if ($acConnector) {
            TariffAssignment::query()->updateOrCreate(
                ['connector_id' => $acConnector->id],
                ['tariff_id' => $nightTariff->id, 'station_id' => null],
            );
        }

        $nour = User::query()->where('email', 'technician@chargetrackr.local')->firstOrFail();
        $karim = User::query()->where('email', 'technician2@chargetrackr.local')->firstOrFail();
        $alertSeed = [
            [
                'reference' => 'ALT-1024', 'station_reference' => 'CT-SFX-017', 'technician_id' => $karim->id,
                'title' => 'Station disconnected', 'problem_type' => 'No heartbeat received', 'severity' => 'critical', 'status' => 'new',
                'description' => 'The station stopped sending OCPP heartbeat messages and cannot accept new sessions.',
                'ocpp_log' => '[08:47:01] Heartbeat timeout. Last BootNotification was accepted before the connection stopped.',
                'suggested_cause' => 'Site router outage or cabinet power interruption.',
                'recommended_action' => 'Check cabinet power, router link LEDs, SIM signal, and local breaker status before replacing hardware.',
                'detected_at' => now()->subHours(2), 'due_at' => now()->addHours(2),
                'events' => ['Alert generated', 'Network retry failed', 'Technician notified'],
            ],
            [
                'reference' => 'ALT-1023', 'station_reference' => 'CT-ARI-006', 'technician_id' => $nour->id,
                'title' => 'Connector faulted', 'problem_type' => 'Connector lock failed during session start', 'severity' => 'critical', 'status' => 'in-progress',
                'description' => 'Connector E1 reported a lock failure and rejected a new charging session twice.',
                'ocpp_log' => '[09:21:14] StatusNotification connectorId=1 status=Faulted errorCode=ConnectorLockFailure',
                'suggested_cause' => 'Lock actuator or cable latch sensor is stuck after a failed session start.',
                'recommended_action' => 'Inspect the connector latch, clean the lock path, test manual release, then retry a status notification cycle.',
                'detected_at' => now()->subHour(), 'due_at' => now()->addHour(),
                'events' => ['Fault received', 'Remote reset attempted', 'Field visit accepted', 'Diagnostic started'],
            ],
            [
                'reference' => 'ALT-1022', 'station_reference' => 'CT-TUN-014', 'technician_id' => $nour->id,
                'title' => 'Over temperature', 'problem_type' => 'Connector temperature threshold exceeded', 'severity' => 'warning', 'status' => 'in-progress',
                'description' => 'The charger reported repeated high-temperature warnings during a fast charging session.',
                'ocpp_log' => '[07:58:32] TemperatureWarning connectorId=1 temperature=72C',
                'suggested_cause' => 'Restricted airflow or connector contact resistance.',
                'recommended_action' => 'Inspect ventilation, filters, cable contacts, and cabinet fans.',
                'detected_at' => now()->subHours(3), 'due_at' => now()->addHours(4),
                'events' => ['Temperature warning received', 'Charging power limited', 'Technician assigned'],
            ],
            [
                'reference' => 'ALT-1019', 'station_reference' => 'CT-BIZ-009', 'technician_id' => $karim->id,
                'title' => 'Maintenance inspection', 'problem_type' => 'Quarterly maintenance due', 'severity' => 'warning', 'status' => 'new',
                'description' => 'Visual inspection and torque check are due before the station returns to production.',
                'ocpp_log' => '[13:15:08] MaintenanceWorkOrder created source=preventive_plan connectorId=1',
                'suggested_cause' => 'Scheduled maintenance cycle reached the quarterly threshold.',
                'recommended_action' => 'Inspect cable jacket, connector pins, emergency stop, grounding, and cabinet ventilation.',
                'detected_at' => now()->subDay(), 'due_at' => now()->addHours(6),
                'events' => ['Work order created', 'Site access confirmed', 'Parts kit prepared'],
            ],
            [
                'reference' => 'ALT-1017', 'station_reference' => 'CT-MON-012', 'technician_id' => $nour->id,
                'title' => 'Payment failed', 'problem_type' => 'Payment authorization rejected', 'severity' => 'info', 'status' => 'resolved',
                'description' => 'A simulated payment authorization failed after the charging session ended.',
                'ocpp_log' => null, 'suggested_cause' => 'Temporary payment adapter rejection.',
                'recommended_action' => 'Review the payment attempt and retry authorization.',
                'detected_at' => now()->subDay(), 'due_at' => now()->subHours(12),
                'events' => ['Payment failed', 'Retry accepted', 'Alert resolved'],
            ],
        ];

        foreach ($alertSeed as $seed) {
            $station = Station::query()->where('reference', $seed['station_reference'])->firstOrFail();
            $connector = $station->connectors()->first();
            $events = $seed['events'];
            $technicianId = $seed['technician_id'];
            unset($seed['station_reference'], $seed['technician_id'], $seed['events']);
            $alert = Alert::updateOrCreate(
                ['reference' => $seed['reference']],
                [
                    ...$seed,
                    'organization_id' => $station->organization_id,
                    'station_id' => $station->id,
                    'connector_id' => $connector?->id,
                    'assigned_technician_id' => $technicianId,
                    'source' => $seed['ocpp_log'] ? 'ocpp' : 'system',
                    'resolved_at' => $seed['status'] === 'resolved' ? now()->subHours(12) : null,
                ],
            );

            foreach ($events as $eventIndex => $description) {
                $alert->events()->firstOrCreate(
                    ['description' => $description],
                    ['actor_id' => null, 'event_type' => 'system', 'occurred_at' => $alert->detected_at->copy()->addMinutes($eventIndex * 12)],
                );
            }
        }

        $interventionSeed = [
            ['reference' => 'INT-8841', 'alert_reference' => 'ALT-1023', 'technician_id' => $nour->id, 'status' => 'in-progress', 'scheduled_at' => now()->subHour(), 'started_at' => now()->subMinutes(50), 'duration' => 90, 'problem' => 'Connector lock actuator does not report locked state.', 'parts' => ['Lock actuator kit', 'Contact cleaner', 'Insulation tester'], 'comments' => 'Waiting for manual lock cycle validation.', 'events' => ['Assigned', 'Started', 'Cabinet opened', 'Diagnostic note added']],
            ['reference' => 'INT-8842', 'alert_reference' => 'ALT-1024', 'technician_id' => $karim->id, 'status' => 'assigned', 'scheduled_at' => now()->addHours(2), 'started_at' => null, 'duration' => 120, 'problem' => 'Station heartbeat stopped and site is unreachable from the OCPP backend.', 'parts' => ['4G router', 'SIM card', 'Fuse kit'], 'comments' => 'Site access contact confirmed.', 'events' => ['Assigned', 'Parts selected', 'Access contact confirmed']],
            ['reference' => 'INT-8838', 'alert_reference' => 'ALT-1019', 'technician_id' => $karim->id, 'status' => 'waiting-parts', 'scheduled_at' => now()->addHours(5), 'started_at' => now()->subDay(), 'duration' => 45, 'problem' => 'Preventive inspection found worn cable strain relief.', 'parts' => ['Strain relief clamp'], 'comments' => 'Station remains in planned maintenance.', 'events' => ['Work order created', 'Inspection started', 'Part requested']],
        ];

        foreach ($interventionSeed as $seed) {
            $alert = Alert::query()->where('reference', $seed['alert_reference'])->firstOrFail();
            $events = $seed['events'];
            $intervention = Intervention::updateOrCreate(
                ['reference' => $seed['reference']],
                [
                    'organization_id' => $organization->id,
                    'alert_id' => $alert->id,
                    'station_id' => $alert->station_id,
                    'connector_id' => $alert->connector_id,
                    'assigned_technician_id' => $seed['technician_id'],
                    'created_by_id' => User::query()->where('email', 'operator@chargetrackr.local')->value('id'),
                    'status' => $seed['status'],
                    'priority' => $alert->severity,
                    'scheduled_at' => $seed['scheduled_at'],
                    'started_at' => $seed['started_at'],
                    'estimated_duration_minutes' => $seed['duration'],
                    'problem' => $seed['problem'],
                    'comments' => $seed['comments'],
                    'parts' => $seed['parts'],
                ],
            );

            foreach ($events as $eventIndex => $description) {
                $intervention->events()->firstOrCreate(
                    ['description' => $description],
                    ['actor_id' => $seed['technician_id'], 'event_type' => 'workflow', 'occurred_at' => now()->subMinutes((count($events) - $eventIndex) * 15)],
                );
            }
        }

        $client = User::query()->where('email', 'client@chargetrackr.local')->firstOrFail();
        $sessionSeeds = [
            [
                'reference' => 'SES-DEMO-ACTIVE', 'station_reference' => 'CT-TUN-014',
                'status' => 'charging', 'payment_status' => 'unpaid', 'started_at' => now()->subMinutes(22),
                'ended_at' => null, 'duration_seconds' => 0, 'energy_kwh' => 0, 'total_millimes' => 0,
            ],
            [
                'reference' => 'SES-DEMO-PAID', 'station_reference' => 'CT-TUN-001',
                'status' => 'completed', 'payment_status' => 'paid', 'started_at' => now()->subDays(2)->subMinutes(41),
                'ended_at' => now()->subDays(2), 'duration_seconds' => 2460, 'energy_kwh' => 28.450, 'total_millimes' => 24683,
            ],
            [
                'reference' => 'SES-DEMO-FAILED', 'station_reference' => 'CT-SOU-022',
                'status' => 'completed', 'payment_status' => 'failed', 'started_at' => now()->subDay()->subMinutes(34),
                'ended_at' => now()->subDay(), 'duration_seconds' => 2040, 'energy_kwh' => 18.200, 'total_millimes' => 15970,
            ],
            [
                'reference' => 'SES-DEMO-UNPAID', 'station_reference' => 'CT-NAB-004',
                'status' => 'completed', 'payment_status' => 'unpaid', 'started_at' => now()->subHours(5),
                'ended_at' => now()->subHours(4)->subMinutes(22), 'duration_seconds' => 2280, 'energy_kwh' => 21.600, 'total_millimes' => 18860,
            ],
            [
                'reference' => 'SES-DEMO-SAHEL', 'station_reference' => 'CT-HAM-031',
                'status' => 'completed', 'payment_status' => 'unpaid', 'started_at' => now()->subDays(3)->subMinutes(29),
                'ended_at' => now()->subDays(3), 'duration_seconds' => 1740, 'energy_kwh' => 16.800, 'total_millimes' => 15856,
            ],
        ];

        foreach ($sessionSeeds as $index => $seed) {
            $station = Station::query()->where('reference', $seed['station_reference'])->firstOrFail();
            $connector = $station->connectors()->orderBy('id')->firstOrFail();
            $resolvedTariff = app(TariffResolver::class)->resolve($station, $connector);
            $session = ChargingSession::updateOrCreate(
                ['reference' => $seed['reference']],
                [
                    'organization_id' => $station->organization_id,
                    'client_id' => $client->id,
                    'station_id' => $station->id,
                    'connector_id' => $connector->id,
                    'tariff_id' => $resolvedTariff->id,
                    'client_name' => $client->name,
                    'station_name' => $station->name,
                    'connector_external_id' => $connector->external_id,
                    'status' => $seed['status'],
                    'payment_status' => $seed['payment_status'],
                    'tariff_name' => $resolvedTariff->name,
                    'started_at' => $seed['started_at'],
                    'ended_at' => $seed['ended_at'],
                    'duration_seconds' => $seed['duration_seconds'],
                    'meter_start_kwh' => 2100 + ($index * 100),
                    'meter_stop_kwh' => $seed['status'] === 'completed' ? 2100 + ($index * 100) + $seed['energy_kwh'] : null,
                    'energy_kwh' => $seed['energy_kwh'],
                    'price_per_kwh_millimes' => $resolvedTariff->pricePerKwhMillimes,
                    'session_fee_millimes' => $resolvedTariff->sessionFeeMillimes,
                    'idle_fee_per_minute_millimes' => $resolvedTariff->idleFeePerMinuteMillimes,
                    'minimum_charge_millimes' => $resolvedTariff->minimumChargeMillimes,
                    'total_millimes' => $seed['total_millimes'],
                    'currency' => 'TND',
                ],
            );

            if ($seed['payment_status'] !== 'unpaid') {
                $paid = $seed['payment_status'] === 'paid';
                Payment::updateOrCreate(
                    ['charging_session_id' => $session->id],
                    [
                        'organization_id' => $station->organization_id,
                        'user_id' => $client->id,
                        'reference' => $paid ? 'PAY-DEMO-PAID' : 'PAY-DEMO-FAILED',
                        'provider' => 'simulated',
                        'method' => $paid ? 'simulated_card' : 'simulated_d17',
                        'status' => $paid ? 'paid' : 'failed',
                        'amount_millimes' => $seed['total_millimes'],
                        'currency' => 'TND',
                        'idempotency_key' => $paid ? '00000000-0000-4000-8000-000000000001' : '00000000-0000-4000-8000-000000000002',
                        'provider_transaction_id' => $paid ? 'SIM-DEMO-PAID' : null,
                        'failure_reason' => $paid ? null : 'Simulated provider decline',
                        'metadata' => ['mode' => 'sandbox'],
                        'paid_at' => $paid ? $seed['ended_at']->copy()->addMinute() : null,
                        'failed_at' => $paid ? null : $seed['ended_at']->copy()->addMinute(),
                    ],
                );
            }
        }

        foreach (Station::query()->get() as $station) {
            $station->update(['open_alerts_count' => $station->alerts()->where('status', '!=', 'resolved')->count()]);
        }
    }
}
