<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('news', 'frame_merged')) {
            Schema::table('news', fn (Blueprint $table) => $table->boolean('frame_merged')->default(false)->after('frame_template'));
        }
    }
    public function down(): void {}
};
