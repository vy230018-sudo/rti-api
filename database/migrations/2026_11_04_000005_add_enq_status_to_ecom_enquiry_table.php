<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('ecom_enquiry', 'enq_status')) {
            Schema::table('ecom_enquiry', function (Blueprint $table) {
                // Applies to both `type = ads` and `type = ecom` records.
                // 1 = new, 2 = review, 3 = closed.
                $table->unsignedTinyInteger('enq_status')->default(1)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ecom_enquiry', 'enq_status')) {
            Schema::table('ecom_enquiry', function (Blueprint $table) {
                $table->dropColumn('enq_status');
            });
        }
    }
};
