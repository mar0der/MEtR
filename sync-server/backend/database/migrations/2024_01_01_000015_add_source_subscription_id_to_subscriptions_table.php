<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'source_subscription_id')) {
                $table->string('source_subscription_id')->nullable()->after('provider_account_id');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unique(['user_id', 'source_subscription_id'], 'sub_user_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique('sub_user_source_unique');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'source_subscription_id')) {
                $table->dropColumn('source_subscription_id');
            }
        });
    }
};
