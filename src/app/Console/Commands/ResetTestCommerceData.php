<?php

namespace App\Console\Commands;

use App\Support\CommerceTestDataResetPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ResetTestCommerceData extends Command
{
    protected $signature = 'commerce:reset-test-data
        {--execute : Permanently delete the test commerce dataset}
        {--confirm= : Exact destructive-operation confirmation token}
        {--database=isc : Expected SQL Server database name}';

    protected $description = 'Inspect or reset test catalog, product, cart, order, and payment data while preserving identities and configuration';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            $this->error('Refusing to run: this guarded operation supports SQL Server only.');

            return self::FAILURE;
        }

        $database = (string) DB::connection()->getDatabaseName();
        $expectedDatabase = trim((string) $this->option('database'));

        if ($expectedDatabase === '' || ! hash_equals(strtolower($expectedDatabase), strtolower($database))) {
            $this->error("Refusing to run: connected database '{$database}' does not match --database='{$expectedDatabase}'.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        if ($execute && ! hash_equals(CommerceTestDataResetPlan::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Execution requires --confirm='.CommerceTestDataResetPlan::CONFIRMATION);

            return self::FAILURE;
        }

        DB::statement('SET NOCOUNT ON');
        DB::statement('SET XACT_ABORT ON');

        $plannedTables = CommerceTestDataResetPlan::deletionTables();
        $existingTables = $this->existingTables($plannedTables);
        $missingTables = array_values(array_diff($plannedTables, $existingTables));
        $requiredTables = ['Products_Master_T', 'Products_Departments_T', 'Orders_Placed_T'];

        if ($missingRequired = array_values(array_diff($requiredTables, $existingTables))) {
            $this->error('Refusing to run against an unexpected schema; required tables are missing: '.implode(', ', $missingRequired));

            return self::FAILURE;
        }

        $counts = $this->tableCounts($existingTables);
        $unexpectedForeignKeys = $this->unexpectedForeignKeys($existingTables);
        $unsafeConstraints = $this->disabledOrUntrustedConstraints($existingTables);
        $preservedTables = $this->existingTables(CommerceTestDataResetPlan::preservedTables());
        $preservedCounts = $this->tableCounts($preservedTables);
        $orderLinkedTickets = $this->orderLinkedSupportTicketCount();
        $staleSliderLinks = $this->staleSliderLinkCount();

        $this->newLine();
        $this->info($execute ? 'EXECUTION PREFLIGHT' : 'DRY-RUN PREFLIGHT');
        $this->line("Database: {$database}");
        $this->line('R2/object storage: untouched');
        $this->line('Identity values: not reseeded');
        $this->line('Order-linked support tickets to delete: '.$orderLinkedTickets);
        $this->line('Preserved slider links that may become stale: '.$staleSliderLinks);
        $this->newLine();
        $this->table(['Delete table', 'Rows'], array_map(
            static fn (string $table): array => [$table, (string) $counts[$table]],
            $existingTables,
        ));

        if ($missingTables !== []) {
            $this->warn('Optional planned tables not present and therefore skipped: '.implode(', ', $missingTables));
        }

        if ($unexpectedForeignKeys !== []) {
            $this->error('Unexpected foreign-key children exist outside the reset plan:');
            $this->table(
                ['Child table', 'Constraint', 'Referenced table', 'Delete action'],
                array_map(static fn (object $row): array => [
                    $row->ChildTable,
                    $row->ConstraintName,
                    $row->ReferencedTable,
                    $row->DeleteAction,
                ], $unexpectedForeignKeys),
            );
        }

        if ($unsafeConstraints !== []) {
            $this->error('Disabled or untrusted constraints involve reset tables:');
            $this->table(
                ['Table', 'Constraint', 'Disabled', 'Untrusted'],
                array_map(static fn (object $row): array => [
                    $row->TableName,
                    $row->ConstraintName,
                    (string) $row->IsDisabled,
                    (string) $row->IsNotTrusted,
                ], $unsafeConstraints),
            );
        }

        if ($unexpectedForeignKeys !== [] || $unsafeConstraints !== []) {
            $this->error('Preflight failed. No data was changed.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->info('Dry run passed. No data was changed.');
            $this->line('Execute only after a verified database backup using:');
            $this->line('php artisan commerce:reset-test-data --execute --confirm='.CommerceTestDataResetPlan::CONFIRMATION);

            return self::SUCCESS;
        }

        try {
            DB::beginTransaction();
            $this->acquireTransactionLock();

            // Repeat all safety-sensitive observations after the exclusive lock.
            if ($this->unexpectedForeignKeys($existingTables) !== []) {
                throw new RuntimeException('Foreign-key topology changed after preflight.');
            }

            $preservedCounts = $this->tableCounts($preservedTables);
            $deleted = [];
            $deleted['Support_Ticket_Messages_T'] = $this->deleteOrderLinkedSupportTicketMessages();
            $deleted['Support_Tickets_T'] = $this->deleteOrderLinkedSupportTickets();

            foreach ($existingTables as $table) {
                $deleted[$table] = DB::affectingStatement('DELETE FROM '.$this->qualified($table));
            }

            $loyaltyBalancesReset = $this->resetCustomerLoyaltyBalances();
            $creditBalancesReset = $this->resetCreditBalances();

            $remaining = array_filter(
                $this->tableCounts($existingTables),
                static fn (int $count): bool => $count !== 0,
            );

            if ($remaining !== []) {
                throw new RuntimeException('Reset verification found non-empty tables: '.implode(', ', array_keys($remaining)));
            }

            $preservedAfter = $this->tableCounts($preservedTables);
            foreach ($preservedCounts as $table => $beforeCount) {
                if (($preservedAfter[$table] ?? null) !== $beforeCount) {
                    throw new RuntimeException("Preserved table count changed for {$table}.");
                }
            }

            if ($this->orderLinkedSupportTicketCount() !== 0) {
                throw new RuntimeException('Order-linked support tickets remain after reset.');
            }

            $constraintErrors = DB::select('DBCC CHECKCONSTRAINTS WITH ALL_CONSTRAINTS');
            if ($constraintErrors !== []) {
                throw new RuntimeException('DBCC CHECKCONSTRAINTS reported integrity violations.');
            }

            DB::commit();

            $this->newLine();
            $this->info('Test commerce data reset committed successfully.');
            $this->line('Rows deleted: '.array_sum($deleted));
            $this->line('Customer loyalty balances reset: '.$loyaltyBalancesReset);
            $this->line('Credit balances reset: '.$creditBalancesReset);
            $this->line('Preserved identity/configuration tables verified: '.count($preservedCounts));
            $this->line('R2/object-storage files were not deleted.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($exception);
            $this->error('Reset failed and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param list<string> $tables @return list<string> */
    private function existingTables(array $tables): array
    {
        return array_values(array_filter($tables, function (string $table): bool {
            $row = DB::selectOne("SELECT OBJECT_ID(N'dbo.{$table}', N'U') AS ObjectId");

            return $row !== null && $row->ObjectId !== null;
        }));
    }

    /** @param list<string> $tables @return array<string, int> */
    private function tableCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $row = DB::selectOne('SELECT COUNT_BIG(*) AS Aggregate FROM '.$this->qualified($table));
            $counts[$table] = (int) ($row->Aggregate ?? 0);
        }

        return $counts;
    }

    /** @param list<string> $tables @return list<object> */
    private function unexpectedForeignKeys(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $sql = <<<SQL
            SELECT
                parent_table.name AS ChildTable,
                foreign_key.name AS ConstraintName,
                referenced_table.name AS ReferencedTable,
                foreign_key.delete_referential_action_desc AS DeleteAction
            FROM sys.foreign_keys AS foreign_key
            INNER JOIN sys.tables AS parent_table ON parent_table.object_id = foreign_key.parent_object_id
            INNER JOIN sys.tables AS referenced_table ON referenced_table.object_id = foreign_key.referenced_object_id
            WHERE referenced_table.name IN ({$placeholders})
              AND parent_table.name NOT IN ({$placeholders})
            ORDER BY parent_table.name, foreign_key.name
            SQL;

        return DB::select($sql, [...$tables, ...$tables]);
    }

    /** @param list<string> $tables @return list<object> */
    private function disabledOrUntrustedConstraints(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $sql = <<<SQL
            SELECT
                parent_table.name AS TableName,
                foreign_key.name AS ConstraintName,
                foreign_key.is_disabled AS IsDisabled,
                foreign_key.is_not_trusted AS IsNotTrusted
            FROM sys.foreign_keys AS foreign_key
            INNER JOIN sys.tables AS parent_table ON parent_table.object_id = foreign_key.parent_object_id
            INNER JOIN sys.tables AS referenced_table ON referenced_table.object_id = foreign_key.referenced_object_id
            WHERE (foreign_key.is_disabled = 1 OR foreign_key.is_not_trusted = 1)
              AND (parent_table.name IN ({$placeholders}) OR referenced_table.name IN ({$placeholders}))
            ORDER BY parent_table.name, foreign_key.name
            SQL;

        return DB::select($sql, [...$tables, ...$tables]);
    }

    private function acquireTransactionLock(): void
    {
        $row = DB::selectOne(<<<'SQL'
            DECLARE @LockResult int;
            EXEC @LockResult = sys.sp_getapplock
                @Resource = N'isc-commerce-test-data-reset',
                @LockMode = N'Exclusive',
                @LockOwner = N'Transaction',
                @LockTimeout = 60000;
            SELECT @LockResult AS LockResult;
            SQL);

        if ($row === null || (int) $row->LockResult < 0) {
            throw new RuntimeException('Unable to acquire the exclusive commerce reset lock.');
        }
    }

    private function deleteOrderLinkedSupportTicketMessages(): int
    {
        if (! $this->tableExists('Support_Ticket_Messages_T') || ! $this->tableExists('Support_Tickets_T')) {
            return 0;
        }

        return DB::affectingStatement(<<<'SQL'
            DELETE message
            FROM [dbo].[Support_Ticket_Messages_T] AS message
            INNER JOIN [dbo].[Support_Tickets_T] AS ticket ON ticket.[id] = message.[Ticket_Id]
            WHERE ticket.[Order_Id] IS NOT NULL
            SQL);
    }

    private function deleteOrderLinkedSupportTickets(): int
    {
        if (! $this->tableExists('Support_Tickets_T')) {
            return 0;
        }

        return DB::affectingStatement('DELETE FROM [dbo].[Support_Tickets_T] WHERE [Order_Id] IS NOT NULL');
    }

    private function orderLinkedSupportTicketCount(): int
    {
        if (! $this->tableExists('Support_Tickets_T')) {
            return 0;
        }

        $row = DB::selectOne('SELECT COUNT_BIG(*) AS Aggregate FROM [dbo].[Support_Tickets_T] WHERE [Order_Id] IS NOT NULL');

        return (int) ($row->Aggregate ?? 0);
    }

    private function staleSliderLinkCount(): int
    {
        if (! $this->tableExists('System_Parameter_UI_Sliders_T') || ! $this->columnExists('System_Parameter_UI_Sliders_T', 'Link_Url')) {
            return 0;
        }

        $row = DB::selectOne(<<<'SQL'
            SELECT COUNT_BIG(*) AS Aggregate
            FROM [dbo].[System_Parameter_UI_Sliders_T]
            WHERE LOWER(COALESCE([Link_Url], '')) LIKE '%/product/%'
               OR LOWER(COALESCE([Link_Url], '')) LIKE '%/department/%'
               OR LOWER(COALESCE([Link_Url], '')) LIKE '%/category/%'
            SQL);

        return (int) ($row->Aggregate ?? 0);
    }

    private function resetCustomerLoyaltyBalances(): int
    {
        if (! $this->tableExists('Customers_Loyalty_T')) {
            return 0;
        }

        return DB::affectingStatement(<<<'SQL'
            UPDATE [dbo].[Customers_Loyalty_T]
            SET [Points_Earned] = 0,
                [Points_Redeemed] = 0,
                [updated_at] = SYSUTCDATETIME()
            WHERE COALESCE([Points_Earned], 0) <> 0
               OR COALESCE([Points_Redeemed], 0) <> 0
            SQL);
    }

    private function resetCreditBalances(): int
    {
        if (! $this->tableExists('Credit_Customers_T') || ! $this->columnExists('Credit_Customers_T', 'Balance_Due')) {
            return 0;
        }

        return DB::affectingStatement(<<<'SQL'
            UPDATE [dbo].[Credit_Customers_T]
            SET [Balance_Due] = 0,
                [updated_at] = SYSUTCDATETIME()
            WHERE COALESCE([Balance_Due], 0) <> 0
            SQL);
    }

    private function tableExists(string $table): bool
    {
        $row = DB::selectOne("SELECT OBJECT_ID(N'dbo.{$table}', N'U') AS ObjectId");

        return $row !== null && $row->ObjectId !== null;
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = DB::selectOne("SELECT COL_LENGTH(N'dbo.{$table}', N'{$column}') AS ColumnLength");

        return $row !== null && $row->ColumnLength !== null;
    }

    private function qualified(string $table): string
    {
        return '[dbo].['.$table.']';
    }
}
