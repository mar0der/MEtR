<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_attribution_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable();
            $table->foreignUlid('provider_account_id')->constrained('provider_accounts')->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('source_path_pattern')->nullable();
            $table->string('model_pattern')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'enabled', 'priority']);
            $table->index(['provider_id', 'device_id']);
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_attribution_rules');
    }
};
