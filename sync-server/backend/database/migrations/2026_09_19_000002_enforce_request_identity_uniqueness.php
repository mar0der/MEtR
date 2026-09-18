<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->dropIndex('usage_events_device_provider_request_index');
            $table->unique(
                ['device_id', 'provider_id', 'request_id'],
                'usage_events_device_provider_request_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->dropUnique('usage_events_device_provider_request_unique');
            $table->index(
                ['device_id', 'provider_id', 'request_id'],
                'usage_events_device_provider_request_index'
            );
        });
    }
};
