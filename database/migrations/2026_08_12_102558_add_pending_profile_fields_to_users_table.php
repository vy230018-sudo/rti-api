<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_phone')->nullable();
            $table->string('pending_bio')->nullable();
            $table->string('pending_profile_image')->nullable();
            $table->tinyInteger('profile_update_status')->default(0)->comment('0: None, 1: Pending, 2: Rejected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_phone', 'pending_bio', 'pending_profile_image', 'profile_update_status']);
        });
    }
};
