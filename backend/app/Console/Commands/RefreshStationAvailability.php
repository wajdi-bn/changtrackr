<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\Availability\AvailabilityProjectionService;
use Illuminate\Console\Command;

class RefreshStationAvailability extends Command
{
    protected $signature = 'availability:refresh {--station= : Station reference, identity or numeric ID}';

    protected $description = 'Recalculate availability for provisioned OCPP 1.6 stations';

    public function handle(AvailabilityProjectionService $projector): int
    {
        $query = Station::query()
            ->where('ocpp_version', 'OCPP 1.6J')
            ->whereNotNull('ocpp_auth_secret_hash');

        if ($lookup = $this->option('station')) {
            $query->where(function ($query) use ($lookup): void {
                $query->where('reference', $lookup)
                    ->orWhere('ocpp_identity', $lookup);

                if (ctype_digit((string) $lookup)) {
                    $query->orWhereKey((int) $lookup);
                }
            });
        }

        $count = 0;
        $query->select('id')->orderBy('id')->chunkById(100, function ($stations) use ($projector, &$count): void {
            foreach ($stations as $station) {
                $projector->project($station->id);
                $count++;
            }
        });

        $this->info("Availability refreshed for {$count} station(s).");

        return self::SUCCESS;
    }
}
