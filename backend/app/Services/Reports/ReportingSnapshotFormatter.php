<?php

namespace App\Services\Reports;

class ReportingSnapshotFormatter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{section: string, indicator: string, result: string|int|float, context: string}>
     */
    public function rows(string $scope, array $payload): array
    {
        return match ($scope) {
            'platform' => $this->platformRows($payload),
            'organization' => $this->organizationRows($payload),
            'operations' => $this->operationsRows($payload),
            'field' => $this->fieldRows($payload),
            default => [],
        };
    }

    /** @param array<string, mixed> $payload */
    private function platformRows(array $payload): array
    {
        $kpis = $payload['kpis'];
        $rows = [
            $this->row('Platform footprint', 'Organizations', $kpis['organizations'], $kpis['active_organizations'].' active'),
            $this->row('Platform footprint', 'Workforce accounts', $kpis['platform_users'], 'Managed employee identities'),
            $this->row('Platform footprint', 'Charging stations', $kpis['managed_stations'], 'Across all organizations'),
            $this->row('Commercial activity', 'Charging sessions', $kpis['sessions'], $payload['period']['label']),
            $this->row('Commercial activity', 'Settled volume', $this->money($kpis['revenue_millimes']), $payload['period']['label']),
        ];
        $rows = [...$rows, ...$this->trendRows($payload['trend'], true)];
        $rows = [...$rows, ...$this->distributionRows('Organization status', $payload['organization_status'])];
        $rows = [...$rows, ...$this->distributionRows('Identity distribution', $payload['user_roles'])];
        foreach ($payload['organization_ranking'] as $index => $organization) {
            $rows[] = $this->row(
                'Organization benchmark',
                ($index + 1).'. '.$organization['name'],
                $this->money($organization['revenue_millimes']),
                $this->humanize($organization['status']).' / '.$organization['stations'].' stations / '.$organization['users'].' users / '.$organization['sessions'].' sessions',
            );
        }
        $rows = [...$rows, ...$this->metricRows('Governance risks', $payload['risk'])];

        return $rows;
    }

    /** @param array<string, mixed> $payload */
    private function organizationRows(array $payload): array
    {
        $rows = [
            $this->row('Business results', 'Settled revenue', $this->money($payload['business']['revenue_millimes']), $payload['period']['label']),
            $this->row('Business results', 'Charging sessions', $payload['business']['sessions'], $payload['business']['energy_kwh'].' kWh delivered'),
            $this->row('Business results', 'Active customers', $payload['business']['customers'], 'Distinct customers in period'),
            ...$this->metricRows('Workforce capacity', $payload['workforce']),
            $this->row('Network posture', 'Managed stations', $payload['network']['stations'], $payload['network']['availability_percent'].'% average availability'),
            $this->row('Network posture', 'Open alerts', $payload['network']['open_alerts'], $payload['network']['sla_breaches'].' SLA breaches'),
            ...$this->trendRows($payload['trend'], true),
            ...$this->distributionRows('Alert severity', $payload['alert_distribution']),
        ];
        foreach ($payload['station_performance'] as $station) {
            $rows[] = $this->row(
                'Station performance',
                $station['name'],
                $station['uptime_percent'].'% availability',
                $this->humanize($station['status']).' / '.$station['sessions'].' sessions / '.$station['energy_kwh'].' kWh / '.$station['open_alerts'].' open alerts',
            );
        }

        return [...$rows, ...$this->metricRows('Report exchange', $payload['report_activity'])];
    }

    /** @param array<string, mixed> $payload */
    private function operationsRows(array $payload): array
    {
        $rows = [
            ...$this->metricRows('Live network state', $payload['live']),
            ...$this->trendRows($payload['trend'], false),
            ...$this->distributionRows('Station state mix', $payload['station_status']),
            ...$this->distributionRows('Alert severity', $payload['alert_severity']),
        ];
        foreach ($payload['station_watchlist'] as $station) {
            $lastSignal = $station['last_heartbeat_at'] ? 'last signal '.$station['last_heartbeat_at'] : 'no station signal';
            $rows[] = $this->row(
                'Station watchlist',
                $station['name'],
                $station['uptime_percent'].'% availability',
                $this->humanize($station['status']).' / '.$station['utilization_percent'].'% utilization / '.$station['open_alerts'].' alerts / '.$lastSignal,
            );
        }

        return [...$rows, ...$this->metricRows('Shift handover', $payload['handover'])];
    }

    /** @param array<string, mixed> $payload */
    private function fieldRows(array $payload): array
    {
        $rows = [
            ...$this->metricRows('My field workload', $payload['workload']),
            ...$this->completionRows($payload['completion_trend']),
            ...$this->distributionRows('Verified outcomes', $payload['outcomes']),
        ];
        foreach ($payload['assignments'] as $assignment) {
            $rows[] = $this->row(
                'Active field queue',
                $assignment['reference'].' / '.($assignment['station'] ?? 'Station unavailable'),
                $this->humanize($assignment['status']),
                $this->humanize($assignment['priority']).' priority / '.$this->humanize($assignment['type']).' / '.($assignment['scheduled_at'] ?? 'not scheduled').' / '.$assignment['problem'],
            );
        }

        return [...$rows, ...$this->metricRows('Report activity', $payload['report_activity'])];
    }

    /** @param array<int, array<string, mixed>> $points */
    private function trendRows(array $points, bool $includeRevenue): array
    {
        return collect($points)->filter(fn (array $point) => ($point['sessions'] ?? 0) > 0 || ($point['energy_kwh'] ?? 0) > 0 || ($point['alerts'] ?? 0) > 0)
            ->map(fn (array $point) => $this->row(
                'Daily activity',
                $point['date'],
                ($point['sessions'] ?? 0).' sessions',
                ($point['energy_kwh'] ?? 0).' kWh'.(isset($point['alerts']) ? ' / '.$point['alerts'].' alerts' : '').($includeRevenue ? ' / '.$this->money($point['revenue_millimes'] ?? 0) : ''),
            ))->values()->all();
    }

    /** @param array<int, array<string, mixed>> $points */
    private function completionRows(array $points): array
    {
        return collect($points)->filter(fn (array $point) => ($point['completed'] ?? 0) > 0)
            ->map(fn (array $point) => $this->row('Completed field work', $point['date'], $point['completed'].' completed jobs', 'Verified final submissions'))
            ->values()->all();
    }

    /** @param array<string, mixed> $metrics */
    private function metricRows(string $section, array $metrics): array
    {
        return collect($metrics)->map(fn (mixed $value, string $key) => $this->row($section, $this->humanize($key), $value, 'Verified platform value'))->values()->all();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function distributionRows(string $section, array $items): array
    {
        return collect($items)->map(fn (array $item) => $this->row($section, $item['label'], $item['value'], 'Records in selected period'))->values()->all();
    }

    /** @return array{section: string, indicator: string, result: string|int|float, context: string} */
    private function row(string $section, string $indicator, string|int|float $result, string $context): array
    {
        return compact('section', 'indicator', 'result', 'context');
    }

    private function money(int|float $millimes): string
    {
        return number_format($millimes / 1000, 3, '.', ' ').' TND';
    }

    private function humanize(string $value): string
    {
        return str_replace(['_', '-'], ' ', ucfirst($value));
    }
}
