<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charging_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('event_id')->unique();
            $table->string('type', 80);
            $table->string('operation', 24);
            $table->string('status', 32);
            $table->string('payment_reference')->nullable()->index();
            $table->string('provider_transaction_id')->nullable()->index();
            $table->string('processing_status', 32)->default('received')->index();
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');
    }
};
