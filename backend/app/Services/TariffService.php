<?php

namespace App\Services;

use App\Models\Tariff;
use Illuminate\Support\Facades\DB;

class TariffService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Tariff
    {
        return DB::transaction(function () use ($attributes): Tariff {
            $this->clearPreviousDefault($attributes['organization_id'], $attributes['is_default'] ?? false);

            return Tariff::query()->create($attributes);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Tariff $tariff, array $attributes): Tariff
    {
        return DB::transaction(function () use ($tariff, $attributes): Tariff {
            $this->clearPreviousDefault($tariff->organization_id, $attributes['is_default'] ?? false, $tariff->id);
            $tariff->update($attributes);

            return $tariff->fresh();
        });
    }

    private function clearPreviousDefault(int $organizationId, bool $isDefault, ?int $exceptId = null): void
    {
        if (! $isDefault) {
            return;
        }

        Tariff::query()
            ->where('organization_id', $organizationId)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
