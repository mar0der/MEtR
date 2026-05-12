<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->text('release_notes')->nullable();
            $table->timestamp('released_at');
            $table->timestamps();
        });

        Schema::create('update_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('update_release_id')->constrained('update_releases')->cascadeOnDelete();
            $table->string('platform');
            $table->string('filename');
            $table->text('signature');
            $table->timestamps();

            $table->unique(['update_release_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_assets');
        Schema::dropIfExists('update_releases');
    }
};
