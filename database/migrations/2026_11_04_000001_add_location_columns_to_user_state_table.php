<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_state', function (Blueprint $table) {
            foreach (['village', 'state_id', 'district_id', 'taluka_id', 'village_id'] as $column) {
                if (! Schema::hasColumn('user_state', $column)) {
                    str_ends_with($column, '_id') ? $table->unsignedBigInteger($column)->nullable() : $table->string($column)->nullable();
                }
            }
        });
    }
    public function down(): void {}
};
