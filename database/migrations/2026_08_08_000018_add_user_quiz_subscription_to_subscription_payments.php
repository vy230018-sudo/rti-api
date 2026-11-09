<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscription_payments', 'user_quiz_subscription_id')) {
            Schema::table('subscription_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('user_quiz_subscription_id')
                    ->nullable()
                    ->after('user_plan_subscription_id');
            });
        }

        $hasForeignKey = collect(Schema::getForeignKeys('subscription_payments'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_quiz_subscription_id']);

        if (! $hasForeignKey) {
            Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreign('user_quiz_subscription_id')
                ->references('id')
                ->on('user_quiz_subscriptions')
                ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('subscription_payments', 'user_quiz_subscription_id')) {
            return;
        }

        $hasForeignKey = collect(Schema::getForeignKeys('subscription_payments'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_quiz_subscription_id']);

        if ($hasForeignKey) {
            Schema::table('subscription_payments', function (Blueprint $table) {
                $table->dropForeign(['user_quiz_subscription_id']);
            });
        }

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('user_quiz_subscription_id');
        });
    }
};
