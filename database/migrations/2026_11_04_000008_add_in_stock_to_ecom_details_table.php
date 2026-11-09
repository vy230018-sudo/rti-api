<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('ecom_deatils', 'in_stock')) {
            Schema::table('ecom_deatils', function (Blueprint $table) {
                // 1 = available, 0 = out of stock
                $table->boolean('in_stock')->default(true)->index();
            });
        }
    }
    public function down(): void {}
};
