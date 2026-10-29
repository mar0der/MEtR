<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anthropic bills 1-hour cache writes at twice the input price (5-minute
 * writes: the cache_write rate). Clients report the 1-hour part separately;
 * it is a subset of cache_write_tokens. Additive, no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->unsignedBigInteger('cache_write_1h_tokens')->default(0)->after('cache_write_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropColumn('cache_write_1h_tokens');
        });
    }
};
