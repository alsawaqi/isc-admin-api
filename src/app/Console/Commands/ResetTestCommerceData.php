<?php

namespace App\Console\Commands;

use App\Support\CommerceTestDataResetPlan;
use App\Support\CommerceTestDataResetSafetyPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
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
        $constraintHealthTables = $this->existingTables(array_values(array_unique([
            ...$plannedTables,
            'Customers_Loyalty_T',
            'Credit_Customers_T',
        ])));
        $missingTables = array_values(array_diff($plannedTables, $existingTables));
        $requiredTables = ['Products_Master_T', 'Products_Departments_T', 'Orders_Placed_T'];

        if ($missingRequired = array_values(array_diff($requiredTables, $existingTables))) {
            $this->error('Refusing to run against an unexpected schema; required tables are missing: '.implode(', ', $missingRequired));

            return self::FAILURE;
        }

        $counts = $this->tableCounts($existingTables);
        $unexpectedForeignKeys = $this->unexpectedForeignKeys($existingTables);
        $constraintHealth = CommerceTestDataResetSafetyPolicy::classifyConstraintHealth(
            $this->constraintHealthFindings($constraintHealthTables),
        );

        try {
            // Read-only integrity verification must pass before an operator may
            // proceed to the backup/delete execution phase.
            $constraintViolations = $this->constraintViolations();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Unable to complete DBCC CHECKCONSTRAINTS preflight. No data was changed: '.$exception->getMessage());

            return self::FAILURE;
        }
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
                ['Child object', 'Constraint', 'Referenced object', 'Delete action'],
                array_map(static fn (object $row): array => [
                    $row->ChildSchema.'.'.$row->ChildTable,
                    $row->ConstraintName,
                    $row->ReferencedSchema.'.'.$row->ReferencedTable,
                    $row->DeleteAction,
                ], $unexpectedForeignKeys),
            );
        }

        if ($constraintHealth['disabled'] !== []) {
            $this->error('Disabled FK or CHECK constraints involve reset or mutated tables and must be enabled before reset:');
            $this->table(
                ['Type', 'Object', 'Constraint', 'Disabled', 'Untrusted'],
                array_map(static fn (object $row): array => [
                    $row->ConstraintType,
                    $row->SchemaName.'.'.$row->TableName,
                    $row->ConstraintName,
                    (string) $row->IsDisabled,
                    (string) $row->IsNotTrusted,
                ], $constraintHealth['disabled']),
            );
        }

        if ($constraintHealth['untrusted'] !== [] && $constraintViolations === []) {
            $this->warn('Enabled but untrusted FK or CHECK constraints involve reset or mutated tables. DBCC found no data violations, so this is a warning rather than a blocker:');
            $this->table(
                ['Type', 'Object', 'Constraint', 'Untrusted'],
                array_map(static fn (object $row): array => [
                    $row->ConstraintType,
                    $row->SchemaName.'.'.$row->TableName,
                    $row->ConstraintName,
                    (string) $row->IsNotTrusted,
                ], $constraintHealth['untrusted']),
            );
        }

        if ($constraintViolations !== []) {
            $this->error('DBCC CHECKCONSTRAINTS found existing integrity violations:');
            $this->table(
                ['Table', 'Constraint', 'Where'],
                array_map(fn (object $row): array => [
                    $this->dbccValue($row, 'Table Name', 'Table'),
                    $this->dbccValue($row, 'Constraint Name', 'Constraint'),
                    $this->dbccValue($row, 'Where'),
                ], $constraintViolations),
            );
        }

        if (CommerceTestDataResetSafetyPolicy::blocksReset(
            $unexpectedForeignKeys,
            $constraintHealth['disabled'],
            $constraintViolations,
        )) {
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
            $lockedConstraintHealth = CommerceTestDataResetSafetyPolicy::classifyConstraintHealth(
                $this->constraintHealthFindings($constraintHealthTables),
            );
            if ($lockedConstraintHealth['disabled'] !== []) {
                throw new RuntimeException('An FK or CHECK constraint involving reset or mutated tables became disabled after preflight.');
            }

            if ($this->constraintViolations() !== []) {
                throw new RuntimeException('DBCC CHECKCONSTRAINTS found integrity violations after the reset lock was acquired.');
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

            $constraintErrors = $this->constraintViolations();
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
                parent_schema.name AS ChildSchema,
                parent_table.name AS ChildTable,
                foreign_key.name AS ConstraintName,
                referenced_schema.name AS ReferencedSchema,
                referenced_table.name AS ReferencedTable,
                foreign_key.delete_referential_action_desc AS DeleteAction
            FROM sys.foreign_keys AS foreign_key
            INNER JOIN sys.tables AS parent_table ON parent_table.object_id = foreign_key.parent_object_id
            INNER JOIN sys.schemas AS parent_schema ON parent_schema.schema_id = parent_table.schema_id
            INNER JOIN sys.tables AS referenced_table ON referenced_table.object_id = foreign_key.referenced_object_id
            INNER JOIN sys.schemas AS referenced_schema ON referenced_schema.schema_id = referenced_table.schema_id
            WHERE referenced_schema.name = N'dbo'
              AND referenced_table.name IN ({$placeholders})
              AND NOT (
                  parent_schema.name = N'dbo'
                  AND parent_table.name IN ({$placeholders})
              )
            ORDER BY parent_schema.name, parent_table.name, foreign_key.name
            SQL;

        return DB::select($sql, [...$tables, ...$tables]);
    }

    /** @param list<string> $tables @return list<object> */
    private function constraintHealthFindings(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $sql = <<<SQL
            SELECT
                N'FOREIGN KEY' AS ConstraintType,
                parent_schema.name AS SchemaName,
                parent_table.name AS TableName,
                foreign_key.name AS ConstraintName,
                foreign_key.is_disabled AS IsDisabled,
                foreign_key.is_not_trusted AS IsNotTrusted
            FROM sys.foreign_keys AS foreign_key
            INNER JOIN sys.tables AS parent_table ON parent_table.object_id = foreign_key.parent_object_id
            INNER JOIN sys.schemas AS parent_schema ON parent_schema.schema_id = parent_table.schema_id
            INNER JOIN sys.tables AS referenced_table ON referenced_table.object_id = foreign_key.referenced_object_id
            INNER JOIN sys.schemas AS referenced_schema ON referenced_schema.schema_id = referenced_table.schema_id
            WHERE (foreign_key.is_disabled = 1 OR foreign_key.is_not_trusted = 1)
              AND (
                  (parent_schema.name = N'dbo' AND parent_table.name IN ({$placeholders}))
                  OR (referenced_schema.name = N'dbo' AND referenced_table.name IN ({$placeholders}))
              )

            UNION ALL

            SELECT
                N'CHECK' AS ConstraintType,
                table_schema.name AS SchemaName,
                constrained_table.name AS TableName,
                check_constraint.name AS ConstraintName,
                check_constraint.is_disabled AS IsDisabled,
                check_constraint.is_not_trusted AS IsNotTrusted
            FROM sys.check_constraints AS check_constraint
            INNER JOIN sys.tables AS constrained_table ON constrained_table.object_id = check_constraint.parent_object_id
            INNER JOIN sys.schemas AS table_schema ON table_schema.schema_id = constrained_table.schema_id
            WHERE (check_constraint.is_disabled = 1 OR check_constraint.is_not_trusted = 1)
              AND table_schema.name = N'dbo'
              AND constrained_table.name IN ({$placeholders})

            ORDER BY SchemaName, TableName, ConstraintName
            SQL;

        return DB::select($sql, [...$tables, ...$tables, ...$tables]);
    }

    /** @return list<object> */
    private function constraintViolations(): array
    {
        $statement = DB::connection()->getPdo()->prepare(
            'DBCC CHECKCONSTRAINTS WITH ALL_CONSTRAINTS, NO_INFOMSGS',
        );

        if ($statement === false || ! $statement->execute()) {
            throw new RuntimeException('DBCC CHECKCONSTRAINTS could not be executed.');
        }

        // PDO_SQLSRV exposes a successful DBCC with no violations as a
        // statement with zero columns; Laravel's DB::select() cannot fetch it.
        if ($statement->columnCount() === 0) {
            return [];
        }

        /** @var list<object> $violations */
        $violations = $statement->fetchAll(PDO::FETCH_OBJ);

        return $violations;
    }

    private function dbccValue(object $row, string ...$columns): string
    {
        foreach ((array) $row as $key => $value) {
            foreach ($columns as $column) {
                if (strcasecmp((string) $key, $column) === 0) {
                    return (string) $value;
                }
            }
        }

        return '';
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
