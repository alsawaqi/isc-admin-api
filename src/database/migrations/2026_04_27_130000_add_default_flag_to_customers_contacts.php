<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Customers_Contact_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Customers_Contact_T', 'Is_Default')) {
                $table->boolean('Is_Default')->default(false);
            }
        });

        DB::statement("
            WITH CustomersWithoutDefault AS (
                SELECT Customers_Contact_Id
                FROM Customers_Contact_T
                WHERE Customers_Contact_Id IS NOT NULL
                GROUP BY Customers_Contact_Id
                HAVING SUM(CASE WHEN Is_Default = 1 THEN 1 ELSE 0 END) = 0
            ),
            RankedContacts AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY Customers_Contact_Id
                        ORDER BY created_at DESC, id DESC
                    ) AS RowNumber
                FROM Customers_Contact_T
                WHERE Customers_Contact_Id IN (
                    SELECT Customers_Contact_Id FROM CustomersWithoutDefault
                )
            )
            UPDATE Customers_Contact_T
            SET Is_Default = 1
            WHERE id IN (
                SELECT id FROM RankedContacts WHERE RowNumber = 1
            )
        ");
    }

    public function down(): void
    {
        Schema::table('Customers_Contact_T', function (Blueprint $table) {
            if (Schema::hasColumn('Customers_Contact_T', 'Is_Default')) {
                $table->dropColumn('Is_Default');
            }
        });
    }
};
