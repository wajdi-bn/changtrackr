<?php

namespace App\Services\Dashboard;

use App\Models\Station;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AvailabilityMetricsCache
{
    private const KEY_PREFIX = 'dashboard:availability';

    /**
     * @template TValue of array
     *
     * @param  Collection<int, Station>  $stations
     * @param  Closure(): TValue  $resolver
     * @return TValue
     */
    public function remember(Collection $stations, Carbon $periodStart, Carbon $periodEnd, Closure $resolver): array
    {
        $scope = $this->scope($stations);
        $version = max(1, (int) Cache::get($this->versionKey($scope), 1));
        $stationFingerprint = hash('sha256', $stations
            ->sortBy('id')
            ->map(fn (Station $station): string => implode(':', [
                $station->id,
                $station->organization_id,
                $station->status,
                $station->availability_monitoring_started_at?->getTimestamp() ?? 'none',
                $station->created_at?->getTimestamp() ?? 'none',
            ]))
            ->implode('|'));
        $key = implode(':', [
            self::KEY_PREFIX,
            $scope,
            'v'.$version,
            $periodStart->getTimestamp(),
            $periodEnd->getTimestamp(),
            $stationFingerprint,
        ]);
        $ttl = max(1, (int) config('availability.dashboard_cache_ttl_seconds', 60));

        return Cache::remember($key, $ttl, $resolver);
    }

    public function invalidateOrganization(int $organizationId): void
    {
        $this->bumpVersion('organization-'.$organizationId);
        $this->bumpVersion('platform');
    }

    /** @param Collection<int, Station> $stations */
    private function scope(Collection $stations): string
    {
        $organizationIds = $stations->pluck('organization_id')->filter()->unique()->values();

        return $organizationIds->count() === 1
            ? 'organization-'.$organizationIds->first()
            : 'platform';
    }

    private function bumpVersion(string $scope): void
    {
        $key = $this->versionKey($scope);

        if (! Cache::add($key, 2)) {
            Cache::increment($key);
        }
    }

    private function versionKey(string $scope): string
    {
        return self::KEY_PREFIX.':version:'.$scope;
    }
}
