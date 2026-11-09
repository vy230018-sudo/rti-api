<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('tittle')->nullable();
            $table->string('sub_tittle')->nullable();
            $table->string('description')->nullable();
            $table->string('report_type')->nullable();
            $table->tinyInteger('media_type')->default(1);
            $table->string('status')->default('pending');
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
