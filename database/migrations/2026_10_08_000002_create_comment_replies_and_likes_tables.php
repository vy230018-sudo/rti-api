<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comment_replies')) {
            Schema::create('comment_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('comment_id');
                $table->unsignedBigInteger('news_id');
                $table->unsignedBigInteger('user_id');
                $table->text('reply');
                $table->tinyInteger('is_deleted')->default(1); // 1 = active, 0 = deleted
                $table->timestamps();

                $table->index(['comment_id', 'is_deleted', 'id']);
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('comment_likes')) {
            Schema::create('comment_likes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('target_type', 10); // 'comment' | 'reply'
                $table->unsignedBigInteger('target_id');
                $table->timestamps();

                $table->unique(['user_id', 'target_type', 'target_id'], 'comment_likes_unique');
                $table->index(['target_type', 'target_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('comment_replies');
    }
};
