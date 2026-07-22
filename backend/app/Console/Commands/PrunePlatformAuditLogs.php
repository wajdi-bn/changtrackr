<?php

namespace App\Console\Commands;

use App\Models\PlatformAuditLog;
use App\Services\PlatformSettingService;
use Illuminate\Console\Command;

class PrunePlatformAuditLogs extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Delete platform audit logs beyond the configured retention period';

    public function handle(PlatformSettingService $settings): int
    {
        $days = $settings->integer('audit_retention_days');
        $deleted = PlatformAuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Deleted {$deleted} audit entries older than {$days} days.");

        return self::SUCCESS;
    }
}
