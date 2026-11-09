<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_plan', 'plan_type')) {
                $table->string('plan_type', 20)->default('role')->after('id');
            }

            // Premium Plan me role empty/null allowed.
            $table->string('role_id', 255)->nullable()->change();
        });

        // Jo plans pehle se "Premium Plan" title ke saath bane hain unhe premium mark karo.
        DB::table('subscription_plan')
            ->whereRaw('LOWER(TRIM(title)) = ?', ['premium plan'])
            ->update(['plan_type' => 'premium', 'role_id' => null]);
    }

    public function down(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_plan', 'plan_type')) {
                $table->dropColumn('plan_type');
            }
        });
        // role_id ko wapas NOT NULL nahi kiya: null rows hone par rollback fail hota.
    }
};
