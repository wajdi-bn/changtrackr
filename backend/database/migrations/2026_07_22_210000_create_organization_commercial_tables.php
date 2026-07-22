<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('monthly_price_millimes');
            $table->unsignedBigInteger('annual_price_millimes');
            $table->unsignedInteger('max_stations')->nullable();
            $table->unsignedInteger('max_employees')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('saas_plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('trialing');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->string('source', 40)->default('manual');
            $table->boolean('auto_renew')->default(false);
            $table->timestampTz('trial_started_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_starts_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->timestampTz('grace_ends_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'trial_ends_at']);
            $table->index(['status', 'current_period_ends_at']);
        });

        Schema::create('organization_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_subscription_id', 'created_at']);
        });

        Schema::create('organization_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saas_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('settled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number', 40)->unique();
            $table->string('status', 20)->default('open');
            $table->string('billing_cycle', 20);
            $table->unsignedBigInteger('amount_millimes');
            $table->string('currency', 3)->default('TND');
            $table->timestampTz('period_starts_at');
            $table->timestampTz('period_ends_at');
            $table->timestampTz('due_at');
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_provider', 40)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invoices');
        Schema::dropIfExists('organization_subscription_events');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('saas_plans');
    }
};
