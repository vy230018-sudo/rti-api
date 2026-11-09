<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_state')) {
            return;
        }

        Schema::create('user_state', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('state')->nullable();
            $table->string('district')->nullable();
            $table->string('taluka')->nullable();
            $table->string('status')->nullable()->default('1');
            $table->string('id_deleted')->default('1');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_state');
    }
};
