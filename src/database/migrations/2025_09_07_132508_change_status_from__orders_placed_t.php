<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
    {
        // Drop old constraint if exists (you must know its name, or query sys.check_constraints)
        DB::statement("
            ALTER TABLE Orders_Placed_T
            DROP CONSTRAINT IF EXISTS CK_Orders_Placed_T_Status
        ");

        // Change the column to NVARCHAR(20) (or larger if needed)
        DB::statement("
            ALTER TABLE Orders_Placed_T
            ALTER COLUMN Status NVARCHAR(20) NOT NULL
        ");

        // Add new CHECK constraint to act like ENUM
        DB::statement("
            ALTER TABLE Orders_Placed_T
            ADD CONSTRAINT CK_Orders_Placed_T_Status
            CHECK (Status IN ('pending', 'processing', 'packed', 'dispatched', 'shipped', 'delivered', 'cancelled', 'returned'))
        ");
    }

    public function down(): void
    {
        // Drop current constraint
        DB::statement("
            ALTER TABLE Orders_Placed_T
            DROP CONSTRAINT IF EXISTS CK_Orders_Placed_T_Status
        ");

        // Restore old constraint (example with original values)
        DB::statement("
            ALTER TABLE Orders_Placed_T
            ADD CONSTRAINT CK_Orders_Placed_T_Status
            CHECK (Status IN ('pending', 'processing', 'packed', 'dispatched', 'shipped', 'delivered'))
        ");
    }
};
