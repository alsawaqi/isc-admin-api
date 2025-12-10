<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Find existing CHECK constraints on the Status column
        $constraints = DB::select("
            SELECT cc.name AS constraint_name
            FROM sys.check_constraints AS cc
            INNER JOIN sys.columns AS col
                ON cc.parent_object_id = col.object_id
               AND cc.parent_column_id = col.column_id
            WHERE cc.parent_object_id = OBJECT_ID('dbo.Orders_Placed_T')
              AND col.name = 'Status'
        ");

        // 2) Drop them
        foreach ($constraints as $constraint) {
            $name = $constraint->constraint_name;

            // Wrap in [] for safety (system-generated names are safe here)
            DB::statement("
                ALTER TABLE [dbo].[Orders_Placed_T]
                DROP CONSTRAINT [{$name}]
            ");
        }

        // 3) Add new CHECK constraint including 'on-hold'
        DB::statement("
            ALTER TABLE [dbo].[Orders_Placed_T]
            ADD CONSTRAINT [CK_Orders_Placed_T_Status]
            CHECK ([Status] IN (
                'pending',
                'processing',
                'packed',
                'dispatched',
                'shipped',
                'delivered',
                'cancelled',
                'returned',
                'on-hold'
            ))
        ");
    }

    public function down(): void
    {
        // 1) Find existing CHECK constraints on the Status column
        $constraints = DB::select("
            SELECT cc.name AS constraint_name
            FROM sys.check_constraints AS cc
            INNER JOIN sys.columns AS col
                ON cc.parent_object_id = col.object_id
               AND cc.parent_column_id = col.column_id
            WHERE cc.parent_object_id = OBJECT_ID('dbo.Orders_Placed_T')
              AND col.name = 'Status'
        ");

        // 2) Drop them
        foreach ($constraints as $constraint) {
            $name = $constraint->constraint_name;

            DB::statement("
                ALTER TABLE [dbo].[Orders_Placed_T]
                DROP CONSTRAINT [{$name}]
            ");
        }

        // 3) Re-add old CHECK constraint (without 'on-hold')
        DB::statement("
            ALTER TABLE [dbo].[Orders_Placed_T]
            ADD CONSTRAINT [CK_Orders_Placed_T_Status]
            CHECK ([Status] IN (
                'pending',
                'processing',
                'packed',
                'dispatched',
                'shipped',
                'delivered',
                'cancelled',
                'returned'
            ))
        ");
    }
};
