<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('usage_events', 'request_id')) {
                $table->string('request_id')->nullable()->after('source_event_hash');
            }
            if (! Schema::hasColumn('usage_events', 'message_id')) {
                $table->string('message_id')->nullable()->after('request_id');
            }
            if (! Schema::hasColumn('usage_events', 'account_hint_hash')) {
                $table->string('account_hint_hash')->nullable()->after('message_id');
            }
        });

        Schema::table('usage_events', function (Blueprint $table): void {
            $table->index(
                ['device_id', 'provider_id', 'request_id'],
                'usage_events_device_provider_request_index'
            );
            $table->index(
                ['device_id', 'account_hint_hash'],
                'usage_events_device_account_hint_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->dropIndex('usage_events_device_provider_request_index');
            $table->dropIndex('usage_events_device_account_hint_index');
            $table->dropColumn(['request_id', 'message_id', 'account_hint_hash']);
        });
    }
};
