<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $columns = [
                'use_frame' => fn () => $table->boolean('use_frame')->default(false),
                'frame_template' => fn () => $table->string('frame_template')->nullable(),
                'music_id' => fn () => $table->unsignedBigInteger('music_id')->nullable(),
                'music_url' => fn () => $table->string('music_url')->nullable(),
                'music_volume' => fn () => $table->decimal('music_volume', 4, 2)->default(1),
                'voice' => fn () => $table->string('voice')->nullable(),
                'mute_original' => fn () => $table->boolean('mute_original')->default(false),
                'original_media' => fn () => $table->string('original_media')->nullable(),
            ];
            foreach ($columns as $name => $add) if (! Schema::hasColumn('news', $name)) $add();
        });
    }
    public function down(): void {}
};
