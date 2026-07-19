<?php

namespace App\Console\Commands;

use App\Services\Ocpp\OcppCommandService;
use Illuminate\Console\Command;

class ExpireOcppCommands extends Command
{
    protected $signature = 'ocpp:expire-commands';

    protected $description = 'Expire unconfirmed OCPP commands and release unused payment authorizations';

    public function handle(OcppCommandService $commands): int
    {
        $count = $commands->expireDue();
        $this->info("Expired {$count} OCPP command(s).");

        return self::SUCCESS;
    }
}
