<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceStatusConstraint('Orders_Placed_Details_T', [
            'pending',
            'processing',
            'packed',
            'dispatched',
            'shipped',
            'ready_for_collection',
            'delivered',
            'cancelled',
            'returned',
            'on-hold',
        ]);

        if (!Schema::hasColumn('Order_Process_Log_T', 'Orders_Placed_Details_Id')) {
            Schema::table('Order_Process_Log_T', function (Blueprint $table) {
                $table->unsignedBigInteger('Orders_Placed_Details_Id')->nullable()->after('Orders_Placed_Id');
                $table->index('Orders_Placed_Details_Id', 'idx_order_process_log_detail_id');

                $table->foreign('Orders_Placed_Details_Id', 'fk_order_process_log_detail')
                    ->references('id')
                    ->on('Orders_Placed_Details_T')
                    ->onUpdate('no action')
                    ->onDelete('no action');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('Order_Process_Log_T', 'Orders_Placed_Details_Id')) {
            Schema::table('Order_Process_Log_T', function (Blueprint $table) {
                $table->dropForeign('fk_order_process_log_detail');
                $table->dropIndex('idx_order_process_log_detail_id');
                $table->dropColumn('Orders_Placed_Details_Id');
            });
        }

        $this->replaceStatusConstraint('Orders_Placed_Details_T', [
            'pending',
            'processing',
            'packed',
            'dispatched',
            'shipped',
            'ready_for_collection',
            'delivered',
            'cancelled',
            'returned',
        ]);
    }

    private function replaceStatusConstraint(string $table, array $statuses): void
    {
        $constraints = DB::select("
            SELECT cc.name AS constraint_name
            FROM sys.check_constraints AS cc
            INNER JOIN sys.columns AS col
                ON cc.parent_object_id = col.object_id
               AND cc.parent_column_id = col.column_id
            WHERE cc.parent_object_id = OBJECT_ID('dbo.{$table}')
              AND col.name = 'Status'
        ");

        foreach ($constraints as $constraint) {
            DB::statement("ALTER TABLE [dbo].[{$table}] DROP CONSTRAINT [{$constraint->constraint_name}]");
        }

        DB::statement("ALTER TABLE [dbo].[{$table}] ALTER COLUMN [Status] NVARCHAR(30) NOT NULL");

        $quotedStatuses = collect($statuses)
            ->map(fn (string $status) => "'" . str_replace("'", "''", $status) . "'")
            ->implode(', ');

        DB::statement("
            ALTER TABLE [dbo].[{$table}]
            ADD CONSTRAINT [CK_{$table}_Status]
            CHECK ([Status] IN ({$quotedStatuses}))
        ");
    }
};
