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
        if (Schema::hasTable('state')) {
            return;
        }

        Schema::create('state', function (Blueprint $table) {
            $table->id();
            $table->string('use_id')->nullable();
            $table->string('state')->nullable();
            $table->string('district')->nullable();
            $table->string('taluka')->nullable();
            $table->string('status')->nullable()->default('1');
            $table->string('id_deleted')->default('1');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('state');
    }
};
