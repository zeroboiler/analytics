<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the analytics_events table for persistent event storage.
     * Indexes are optimized for common query patterns:
     * - Lookup by event name (most common query)
     * - Time-range queries (dashboard, reporting)
     * - User-scoped queries (GDPR erasure, profile analytics)
     * - Client ID queries (session reconstruction)
     * - Category/provider filters
     * - Idempotency deduplication
     */
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Core event data
            $table->string('name', 100)->index();
            $table->string('category', 50)->nullable()->index();
            $table->json('params')->nullable();

            // Identity
            $table->string('user_id', 100)->nullable()->index();
            $table->string('client_id', 100)->nullable()->index();
            $table->string('session_id', 100)->nullable()->index();

            // Provider & source
            $table->string('provider', 50)->nullable()->index();
            $table->string('source', 50)->nullable();

            // Context
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('consent_state', 20)->nullable();
            $table->string('fingerprint', 64)->nullable()->index();

            // Quality & dedup
            $table->unsignedTinyInteger('priority')->default(1);
            $table->boolean('dedup')->default(true);
            $table->string('idempotency_key', 100)->nullable()->index();

            // Composite indexes for common query patterns
            $table->index(['name', 'created_at'], 'events_name_time');
            $table->index(['user_id', 'created_at'], 'events_user_time');
            $table->index(['client_id', 'created_at'], 'events_client_time');
            $table->index(['category', 'provider'], 'events_category_provider');

            $table->timestamp('created_at')->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
