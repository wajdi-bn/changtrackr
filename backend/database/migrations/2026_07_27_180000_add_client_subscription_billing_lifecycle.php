<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->string('payment_method', 40)->default('simulated_card')->after('billing_provider');
            $table->boolean('cancel_at_period_end')->default(false)->after('auto_renew');
            $table->timestampTz('cancellation_requested_at')->nullable()->after('current_period_ends_at');
            $table->timestampTz('past_due_at')->nullable()->after('cancellation_requested_at');
            $table->timestampTz('grace_ends_at')->nullable()->after('past_due_at');
            $table->timestampTz('last_renewed_at')->nullable()->after('grace_ends_at');
            $table->timestampTz('ended_at')->nullable()->after('last_renewed_at');
            $table->index(['status', 'current_period_ends_at'], 'plan_subscriptions_lifecycle_index');
        });

        Schema::create('plan_subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charging_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('billing_reason', 24);
            $table->string('payment_provider', 40);
            $table->string('payment_method', 40);
            $table->string('provider_transaction_id')->nullable()->index();
            $table->string('idempotency_key', 120)->unique();
            $table->unsignedInteger('amount_millimes');
            $table->string('currency', 3)->default('TND');
            $table->timestampTz('period_starts_at');
            $table->timestampTz('period_ends_at');
            $table->timestampTz('due_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['organization_id', 'status']);
            $table->index(['plan_subscription_id', 'billing_reason']);
        });

        Schema::table('payment_provider_events', function (Blueprint $table): void {
            $table->foreignId('plan_subscription_invoice_id')
                ->nullable()
                ->after('payment_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_subscription_invoice_id');
        });

        Schema::dropIfExists('plan_subscription_invoices');

        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('plan_subscriptions_lifecycle_index');
            $table->dropColumn([
                'payment_method',
                'cancel_at_period_end',
                'cancellation_requested_at',
                'past_due_at',
                'grace_ends_at',
                'last_renewed_at',
                'ended_at',
            ]);
        });
    }
};
