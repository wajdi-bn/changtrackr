<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40)->index();
            $table->string('severity', 24)->default('info')->index();
            $table->string('title', 180);
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('deduplication_key', 191);
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'deduplication_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['organization_id', 'category']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_notification_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_notification_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('user_notifications');
    }
};
