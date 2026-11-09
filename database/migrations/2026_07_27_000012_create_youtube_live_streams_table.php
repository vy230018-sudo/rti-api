<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('youtube_live_streams', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('broadcast_id')->unique();
            $table->string('stream_id')->unique();
            $table->text('stream_key'); // encrypted by the model cast
            $table->string('ingestion_address');
            $table->string('watch_url');
            $table->string('embed_url');
            $table->string('privacy_status', 20);
            $table->string('status', 30)->default('created');
            $table->timestamp('scheduled_start_time')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // One encrypted refresh token per authorized channel/application.
            $table->text('refresh_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_live_streams');
    }
};
