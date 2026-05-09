<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id');
            $table->foreignUlid('provider_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_attribution_confidence')->default('unknown');
            $table->string('account_attribution_reason')->nullable();
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_event_id');
            $table->string('source_event_hash');
            $table->string('source_file_hash')->nullable();
            $table->bigInteger('source_offset')->nullable();
            $table->timestamp('timestamp');
            $table->string('model')->nullable();
            $table->bigInteger('input_tokens')->default(0);
            $table->bigInteger('output_tokens')->default(0);
            $table->bigInteger('cached_input_tokens')->default(0);
            $table->bigInteger('cache_write_tokens')->default(0);
            $table->bigInteger('cache_read_tokens')->default(0);
            $table->bigInteger('reasoning_tokens')->default(0);
            $table->bigInteger('tool_tokens')->default(0);
            $table->bigInteger('unknown_tokens')->default(0);
            $table->decimal('official_api_cost_usd', 20, 10)->nullable();
            $table->foreignUlid('model_price_id')->nullable()->constrained('model_prices')->nullOnDelete();
            $table->string('pricing_match_confidence')->default('missing');
            $table->json('warnings_json')->nullable();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'source_event_id']);
            $table->index(['user_id', 'timestamp']);
            $table->index(['user_id', 'provider_id', 'timestamp']);
            $table->index(['user_id', 'project_id', 'timestamp']);
            $table->index(['user_id', 'provider_account_id', 'timestamp']);
            $table->index(['model']);
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
