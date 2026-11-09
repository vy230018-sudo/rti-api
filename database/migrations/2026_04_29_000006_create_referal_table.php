<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referal')) {
            return;
        }

        Schema::create('referal', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('from_user_id')->nullable();
            $table->string('refral_code')->nullable();
            $table->string('amount')->nullable();
            $table->string('level')->nullable();
            $table->string('status')->default('1');
            $table->tinyInteger('is_deleted')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referal');
    }
};
