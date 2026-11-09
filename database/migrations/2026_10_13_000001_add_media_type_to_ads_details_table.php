<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older installations use the historical `ads_deatils` spelling.
        $table = Schema::hasTable('ads_deatils') ? 'ads_deatils' : 'ads_details';

        if (! Schema::hasColumn($table, 'media_type')) {
            Schema::table($table, function (Blueprint $table) {
                $table->tinyInteger('media_type')->default(1)->after('image');
            });
        }
    }

    public function down(): void
    {
        $table = Schema::hasTable('ads_deatils') ? 'ads_deatils' : 'ads_details';

        if (Schema::hasColumn($table, 'media_type')) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('media_type');
            });
        }
    }
};
