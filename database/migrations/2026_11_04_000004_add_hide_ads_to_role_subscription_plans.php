<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('subscription_plan', 'hide_ads')) {
            Schema::table('subscription_plan', function (Blueprint $table) {
                // Set by an administrator per role/premium subscription plan.
                // 1 = active subscribers of this plan do not receive ads.
                $table->boolean('hide_ads')->default(false)->after('credits');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscription_plan', 'hide_ads')) {
            Schema::table('subscription_plan', function (Blueprint $table) {
                $table->dropColumn('hide_ads');
            });
        }
    }
};
