<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'profile_image_change_count')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('profile_image_change_count')->default(0)->after('profile_image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'profile_image_change_count')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('profile_image_change_count');
            });
        }
    }
};
