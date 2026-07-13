<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40);
            $table->text('description')->nullable();
            $table->unsignedInteger('monthly_fee_millimes')->default(0);
            $table->unsignedSmallInteger('discount_basis_points')->default(0);
            $table->string('audience', 120);
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_plans');
    }
};
