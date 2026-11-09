<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('newspapers', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('published_for_date');
            $table->string('thumbnail')->nullable();
            $table->string('pdf')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('newspapers'); }
};
