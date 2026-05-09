<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider_id');
            $table->string('model');
            $table->json('aliases_json')->nullable();
            $table->string('currency')->default('USD');
            $table->decimal('input_per_1m', 20, 10)->nullable();
            $table->decimal('output_per_1m', 20, 10)->nullable();
            $table->decimal('cached_input_per_1m', 20, 10)->nullable();
            $table->decimal('cache_write_per_1m', 20, 10)->nullable();
            $table->decimal('cache_read_per_1m', 20, 10)->nullable();
            $table->decimal('reasoning_per_1m', 20, 10)->nullable();
            $table->decimal('tool_per_1m', 20, 10)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->string('source_url')->nullable();
            $table->string('source_hash')->nullable();
            $table->string('catalog_version')->nullable();
            $table->boolean('user_override')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'model']);
            $table->index(['provider_id', 'model', 'effective_from', 'effective_to']);
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_prices');
    }
};
