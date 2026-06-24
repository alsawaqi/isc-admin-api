<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceStatusConstraint(
            'Orders_Placed_T',
            [
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
            ],
            30
        );

        $this->replaceStatusConstraint(
            'Orders_Placed_Details_T',
            [
                'pending',
                'processing',
                'packed',
                'dispatched',
                'shipped',
                'ready_for_collection',
                'delivered',
                'cancelled',
                'returned',
            ],
            30
        );
    }

    public function down(): void
    {
        $this->replaceStatusConstraint(
            'Orders_Placed_T',
            [
                'pending',
                'processing',
                'packed',
                'dispatched',
                'shipped',
                'delivered',
                'cancelled',
                'returned',
                'on-hold',
            ],
            20
        );

        $this->replaceStatusConstraint(
            'Orders_Placed_Details_T',
            [
                'pending',
                'processing',
                'packed',
                'dispatched',
                'shipped',
                'delivered',
                'cancelled',
                'returned',
            ],
            20
        );
    }

    private function replaceStatusConstraint(string $table, array $statuses, int $length): void
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

        DB::statement("ALTER TABLE [dbo].[{$table}] ALTER COLUMN [Status] NVARCHAR({$length}) NOT NULL");

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
