<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reportable_type', 20); // news or user
            $table->unsignedBigInteger('reportable_id');
            $table->string('reason', 255);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['reporter_id', 'reportable_type', 'reportable_id']);
            $table->index(['reportable_type', 'reportable_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
