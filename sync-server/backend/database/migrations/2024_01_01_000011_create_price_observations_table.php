<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_observations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider_id');
            $table->string('source_url');
            $table->timestamp('fetched_at');
            $table->string('source_hash');
            $table->json('parsed_json')->nullable();
            $table->string('status')->default('parsed');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_observations');
    }
};
