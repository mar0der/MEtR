<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Index for sync loop: find pending events by user, ordered by timestamp
        Schema::table('usage_events', function (Blueprint $table) {
            $table->index(['user_id', 'synced_at', 'timestamp'], 'idx_ue_user_synced_timestamp');
        });

        // Index for model-filtered reports
        Schema::table('usage_events', function (Blueprint $table) {
            $table->index(['user_id', 'model', 'timestamp'], 'idx_ue_user_model_timestamp');
        });

        // Index for conversation lookups during sync ingestion
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['user_id', 'provider_id', 'device_id', 'external_conversation_id'], 'idx_conv_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex('idx_ue_user_synced_timestamp');
            $table->dropIndex('idx_ue_user_model_timestamp');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conv_lookup');
        });
    }
};
