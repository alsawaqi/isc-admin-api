<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('System_Parameter_Loyalty_Points_T', function (Blueprint $table) {
            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_T', 'Earn_Amount')) {
                $table->decimal('Earn_Amount', 10, 3)->nullable()->after('Point');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_T', 'Earn_Points')) {
                $table->decimal('Earn_Points', 10, 3)->nullable()->after('Earn_Amount');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_T', 'Redeem_Points')) {
                $table->decimal('Redeem_Points', 10, 3)->nullable()->after('Earn_Points');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_T', 'Redeem_Amount')) {
                $table->decimal('Redeem_Amount', 10, 3)->nullable()->after('Redeem_Points');
            }
        });

        DB::statement("
            UPDATE System_Parameter_Loyalty_Points_T
            SET
                Earn_Amount = COALESCE(Earn_Amount, 1),
                Earn_Points = COALESCE(Earn_Points, Point, 0),
                Redeem_Points = COALESCE(Redeem_Points, 1000),
                Redeem_Amount = COALESCE(Redeem_Amount, 1)
        ");

        Schema::table('System_Parameter_Loyalty_Points_History_T', function (Blueprint $table) {
            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Current_Earn_Amount')) {
                $table->decimal('Current_Earn_Amount', 10, 3)->nullable()->after('Previous_Point');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Previous_Earn_Amount')) {
                $table->decimal('Previous_Earn_Amount', 10, 3)->nullable()->after('Current_Earn_Amount');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Current_Earn_Points')) {
                $table->decimal('Current_Earn_Points', 10, 3)->nullable()->after('Previous_Earn_Amount');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Previous_Earn_Points')) {
                $table->decimal('Previous_Earn_Points', 10, 3)->nullable()->after('Current_Earn_Points');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Current_Redeem_Points')) {
                $table->decimal('Current_Redeem_Points', 10, 3)->nullable()->after('Previous_Earn_Points');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Previous_Redeem_Points')) {
                $table->decimal('Previous_Redeem_Points', 10, 3)->nullable()->after('Current_Redeem_Points');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Current_Redeem_Amount')) {
                $table->decimal('Current_Redeem_Amount', 10, 3)->nullable()->after('Previous_Redeem_Points');
            }

            if (!Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', 'Previous_Redeem_Amount')) {
                $table->decimal('Previous_Redeem_Amount', 10, 3)->nullable()->after('Current_Redeem_Amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('System_Parameter_Loyalty_Points_History_T', function (Blueprint $table) {
            $columns = [
                'Current_Earn_Amount',
                'Previous_Earn_Amount',
                'Current_Earn_Points',
                'Previous_Earn_Points',
                'Current_Redeem_Points',
                'Previous_Redeem_Points',
                'Current_Redeem_Amount',
                'Previous_Redeem_Amount',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('System_Parameter_Loyalty_Points_History_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('System_Parameter_Loyalty_Points_T', function (Blueprint $table) {
            $columns = [
                'Earn_Amount',
                'Earn_Points',
                'Redeem_Points',
                'Redeem_Amount',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('System_Parameter_Loyalty_Points_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
