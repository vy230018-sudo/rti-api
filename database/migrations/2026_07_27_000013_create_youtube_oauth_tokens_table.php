<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('youtube_oauth_tokens', function (Blueprint $table): void {
            $table->id();
            $table->text('refresh_token');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('youtube_oauth_tokens'); }
};
