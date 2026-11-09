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
            if (! Schema::hasColumn('subscription_plan', 'location_level')) {
                $table->enum('location_level', ['state', 'district', 'taluka', 'village'])->nullable()->after('role_id');
            }

            // Older plans keep their fixed geography, but new level plans send these as empty values.
            foreach (['state', 'district', 'taluka', 'village'] as $column) {
                if (Schema::hasColumn('subscription_plan', $column)) {
                    $table->string($column, 255)->nullable()->change();
                } else {
                    $table->string($column, 255)->nullable();
                }
            }
        });

        // Existing plans continue to work with their existing fixed locations. Giving them a
        // level also makes their intended comparison scope explicit for future reads.
        DB::table('subscription_plan')->whereNull('location_level')->update([
            'location_level' => DB::raw("CASE
                WHEN CAST(role_id AS UNSIGNED) BETWEEN 6 AND 10 THEN 'state'
                WHEN CAST(role_id AS UNSIGNED) BETWEEN 11 AND 15 THEN 'district'
                WHEN CAST(role_id AS UNSIGNED) BETWEEN 16 AND 20 THEN 'taluka'
                WHEN CAST(role_id AS UNSIGNED) BETWEEN 21 AND 25 THEN 'village'
                ELSE NULL END"),
        ]);

        // This application calls purchases/seats `seats`; it is the role-subscription
        // purchase record and already holds status, reservation and expiry details.
        if (Schema::hasTable('seats')) {
            Schema::table('seats', function (Blueprint $table) {
                foreach (['role_id', 'state', 'district', 'taluka', 'village'] as $column) {
                    if (! Schema::hasColumn('seats', $column)) {
                        $table->string($column, 255)->nullable();
                    }
                }
                if (! Schema::hasColumn('seats', 'reserved_until')) {
                    $table->timestamp('reserved_until')->nullable();
                }
                if (! Schema::hasColumn('seats', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable();
                }
            });

            // Some older installations have legacy seats without a plan_id. Those rows
            // cannot be reliably backfilled and remain legacy records rather than making
            // the whole deployment fail.
            if (Schema::hasColumn('seats', 'plan_id')) {
                DB::statement('UPDATE seats s INNER JOIN subscription_plan p ON p.id = s.plan_id SET s.role_id = p.role_id WHERE s.role_id IS NULL');
            }
            // Prefixes keep this index compatible with legacy MySQL/InnoDB instances
            // that have a 1000-byte maximum key length under utf8mb4.
            DB::statement('CREATE INDEX seats_role_location_status_expiry_index ON seats (role_id(40), state(40), district(40), taluka(40), village(40), status, expires_at)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('seats')) {
            Schema::table('seats', function (Blueprint $table) {
                $table->dropIndex('seats_role_location_status_expiry_index');
            });
        }

        Schema::table('subscription_plan', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_plan', 'location_level')) {
                $table->dropColumn('location_level');
            }
        });
    }
};
