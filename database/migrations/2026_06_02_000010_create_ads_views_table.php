<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ads_id');
            $table->unsignedInteger('count')->default(1);
            $table->tinyInteger('status')->default(1);
            $table->tinyInteger('is_deleted')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'ads_id']);
            $table->index(['ads_id', 'status', 'is_deleted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_views');
    }
};
