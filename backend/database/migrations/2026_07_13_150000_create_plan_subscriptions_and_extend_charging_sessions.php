<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charging_plan_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->boolean('auto_renew')->default(true);
            $table->string('billing_provider')->default('simulated');
            $table->unsignedInteger('monthly_fee_millimes');
            $table->unsignedSmallInteger('discount_basis_points');
            $table->timestamp('starts_at');
            $table->timestamp('current_period_ends_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'organization_id', 'status'], 'subscriptions_user_org_status_index');
            $table->index(['charging_plan_id', 'status']);
        });

        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->foreignId('charging_plan_id')->nullable()->after('tariff_id')->constrained()->nullOnDelete();
            $table->string('charging_plan_name')->nullable()->after('tariff_name');
            $table->unsignedSmallInteger('discount_basis_points')->default(0)->after('charging_plan_name');
            $table->unsignedInteger('discount_millimes')->default(0)->after('discount_basis_points');
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('charging_plan_id');
            $table->dropColumn(['charging_plan_name', 'discount_basis_points', 'discount_millimes']);
        });

        Schema::dropIfExists('plan_subscriptions');
    }
};
