<?php

namespace App\Services\Reports;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    /**
     * @param  array<string, string>  $columns
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function dataset(
        string $format,
        string $filename,
        string $title,
        string $subtitle,
        array $columns,
        Collection|array $rows,
        User $actor,
        array $filters = [],
    ): JsonResponse|StreamedResponse|Response {
        $rows = collect($rows)->values();

        if ($format === 'json') {
            return response()->json([
                'metadata' => $this->metadata($title, $subtitle, $actor, $filters, $rows->count()),
                'data' => $rows,
            ]);
        }

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($columns, $rows): void {
                $output = fopen('php://output', 'w');
                if ($output === false) {
                    return;
                }
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, array_values($columns));
                foreach ($rows as $row) {
                    fputcsv($output, array_map(fn (string $key) => $this->scalar($row[$key] ?? null), array_keys($columns)));
                }
                fclose($output);
            }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $metadata = $this->metadata($title, $subtitle, $actor, $filters, $rows->count());

        return Pdf::loadView('reports.dataset', compact('title', 'subtitle', 'columns', 'rows', 'metadata'))
            ->setPaper('a4', count($columns) > 7 ? 'landscape' : 'portrait')
            ->download("{$filename}.pdf");
    }

    /** @param array<string, mixed> $filters */
    private function metadata(string $title, string $subtitle, User $actor, array $filters, int $rowCount): array
    {
        $actor->loadMissing('organization');

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'generated_at' => now()->timezone($actor->timezone ?: config('app.timezone'))->format('d M Y, H:i'),
            'generated_by' => $actor->name,
            'scope' => $actor->organization?->name ?? 'ChargeTrackr platform',
            'filters' => collect($filters)->reject(fn ($value) => $value === null || $value === '')->all(),
            'row_count' => $rowCount,
        ];
    }

    private function scalar(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return $value ?? '';
    }
}
