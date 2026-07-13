<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('currency', 3)->default('TND');
            $table->unsignedInteger('price_per_kwh_millimes');
            $table->unsignedInteger('session_fee_millimes')->default(0);
            $table->unsignedInteger('idle_fee_per_minute_millimes')->default(0);
            $table->unsignedInteger('minimum_charge_millimes')->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status', 'valid_from', 'valid_until']);
        });

        Schema::create('tariff_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('station_id');
            $table->unique('connector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_assignments');
        Schema::dropIfExists('tariffs');
    }
};
