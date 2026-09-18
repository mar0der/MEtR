<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->index(
                ['device_id', 'provider_id', 'source_event_hash'],
                'usage_events_device_provider_hash_index'
            );
            $table->index(
                ['device_id', 'provider_id', 'message_id'],
                'usage_events_device_provider_message_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->dropIndex('usage_events_device_provider_hash_index');
            $table->dropIndex('usage_events_device_provider_message_index');
        });
    }
};
