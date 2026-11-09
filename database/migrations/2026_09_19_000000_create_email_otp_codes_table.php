<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_otp_codes')) {
            Schema::table('email_otp_codes', function (Blueprint $table) {
                $table->string('email', 191)->change();
                $table->unique('email');
            });

            return;
        }

        Schema::create('email_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191);
            $table->string('purpose', 32);
            $table->string('token');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otp_codes');
    }
};
