<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invoices', function (Blueprint $table): void {
            $table->string('payment_method', 40)->nullable()->after('payment_provider');
            $table->uuid('idempotency_key')->nullable()->unique()->after('provider_reference');
            $table->timestampTz('failed_at')->nullable()->after('paid_at');
            $table->text('failure_reason')->nullable()->after('failed_at');
            $table->json('provider_metadata')->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invoices', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['payment_method', 'idempotency_key', 'failed_at', 'failure_reason', 'provider_metadata']);
        });
    }
};
