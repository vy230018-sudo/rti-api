<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_enquiry', function (Blueprint $table) {
            $table->string('type')->default('ecom')->after('ecom_id');
        });
    }

    public function down(): void
    {
        Schema::table('ecom_enquiry', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
