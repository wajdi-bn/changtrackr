<?php

namespace Tests\Unit;

use App\Services\Reports\ReportingSnapshotFormatter;
use PHPUnit\Framework\TestCase;

class ReportingSnapshotFormatterTest extends TestCase
{
    public function test_platform_snapshot_is_formatted_as_readable_business_rows(): void
    {
        $rows = (new ReportingSnapshotFormatter)->rows('platform', [
            'period' => ['label' => 'Last 7 days'],
            'kpis' => [
                'organizations' => 2,
                'active_organizations' => 1,
                'platform_users' => 8,
                'managed_stations' => 6,
                'sessions' => 12,
                'revenue_millimes' => 123450,
            ],
            'trend' => [['date' => '2026-08-10', 'sessions' => 3, 'energy_kwh' => 44.5, 'revenue_millimes' => 12500]],
            'organization_status' => [['label' => 'Active', 'value' => 1]],
            'user_roles' => [['label' => 'Operator', 'value' => 3]],
            'organization_ranking' => [[
                'name' => 'Tunis Network', 'status' => 'active', 'stations' => 4,
                'users' => 5, 'sessions' => 10, 'revenue_millimes' => 110000,
            ]],
            'risk' => ['inactive_organizations' => 1, 'offline_stations' => 2, 'critical_alerts' => 1, 'failed_payments' => 0],
        ]);

        $this->assertSame('Platform footprint', $rows[0]['section']);
        $this->assertSame('2', (string) $rows[0]['result']);
        $this->assertSame('123.450 TND', $rows[4]['result']);
        $this->assertStringContainsString('4 stations / 5 users / 10 sessions', collect($rows)->firstWhere('section', 'Organization benchmark')['context']);
        $this->assertRowsContainOnlyScalarCells($rows);
    }

    public function test_field_snapshot_keeps_assignment_context_human_readable(): void
    {
        $rows = (new ReportingSnapshotFormatter)->rows('field', [
            'workload' => ['assigned' => 1, 'in_progress' => 1, 'completed' => 2, 'overdue' => 0, 'average_minutes' => 45],
            'completion_trend' => [['date' => '2026-08-09', 'completed' => 2]],
            'outcomes' => [['label' => 'Operational', 'value' => 2]],
            'assignments' => [[
                'reference' => 'INT-101', 'station' => 'Lac 1', 'status' => 'in-progress',
                'priority' => 'high', 'type' => 'intervention', 'scheduled_at' => null,
                'problem' => 'Connector lock requires diagnosis.',
            ]],
            'report_activity' => ['unread_reports' => 1, 'reports_received' => 2, 'reports_sent' => 3, 'draft_reports' => 1, 'field_reports_submitted' => 4],
        ]);

        $assignment = collect($rows)->firstWhere('section', 'Active field queue');
        $this->assertSame('INT-101 / Lac 1', $assignment['indicator']);
        $this->assertStringContainsString('Connector lock requires diagnosis.', $assignment['context']);
        $this->assertRowsContainOnlyScalarCells($rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function assertRowsContainOnlyScalarCells(array $rows): void
    {
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $this->assertTrue(is_scalar($value));
                if (is_string($value)) {
                    $this->assertFalse(str_starts_with(trim($value), '{'));
                    $this->assertFalse(str_starts_with(trim($value), '['));
                }
            }
        }
    }
}
