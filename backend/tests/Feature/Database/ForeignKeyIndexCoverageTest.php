<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ForeignKeyIndexCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_volume_foreign_keys_have_non_redundant_indexes(): void
    {
        $this->assertIndex('payment_provider_events', 'payment_provider_events_organization_id_index', ['organization_id']);
        $this->assertIndex('availability_transitions', 'availability_transitions_organization_id_index', ['organization_id']);
        $this->assertIndex('platform_audit_logs', 'platform_audit_logs_actor_id_created_at_index', ['actor_id', 'created_at']);
        $this->assertIndex('charging_attempts', 'charging_attempts_organization_id_index', ['organization_id']);
        $this->assertIndex('ocpp_commands', 'ocpp_commands_organization_id_index', ['organization_id']);

        $this->assertIndex('ocpp_events', 'ocpp_events_organization_id_occurred_at_index', ['organization_id', 'occurred_at']);
        $this->assertIndex('user_notifications', 'user_notifications_organization_id_category_index', ['organization_id', 'category']);
    }

    /** @param list<string> $columns */
    private function assertIndex(string $table, string $name, array $columns): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

        $this->assertNotNull($index, "Expected index [{$name}] on [{$table}].");
        $this->assertSame($columns, $index['columns']);
    }
}
