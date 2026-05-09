<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->string('client_batch_id');
            $table->string('direction');
            $table->string('status')->default('received');
            $table->integer('event_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'client_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_batches');
    }
};
