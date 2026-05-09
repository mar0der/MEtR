<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id');
            $table->string('label');
            $table->string('account_type')->default('unknown');
            $table->foreignUlid('default_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('external_account_hint_hash')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider_id']);
            $table->index(['user_id', 'active']);
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_accounts');
    }
};
