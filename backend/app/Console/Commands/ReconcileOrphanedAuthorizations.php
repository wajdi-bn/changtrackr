<?php

namespace App\Console\Commands;

use App\Services\Payments\OrphanedAuthorizationReconciliationService;
use Illuminate\Console\Command;

class ReconcileOrphanedAuthorizations extends Command
{
    protected $signature = 'payments:reconcile-orphan-authorizations';

    protected $description = 'Capture or release stale OCPP payment authorizations after the connectivity grace period';

    public function handle(OrphanedAuthorizationReconciliationService $reconciliation): int
    {
        $result = $reconciliation->scan();
        $this->info(
            "Authorization reconciliation completed: {$result['captured']} captured, "
            ."{$result['released']} released, {$result['failed']} failed, {$result['skipped']} skipped.",
        );

        return self::SUCCESS;
    }
}
