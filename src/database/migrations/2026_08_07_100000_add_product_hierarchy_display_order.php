<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORDER_STEP = 1_000_000_000;

    private const STATE_TABLE = 'Product_Hierarchy_Display_Order_State_T';

    /** @var array<int, array{table: string, parent: ?string, source: string, index: string}> */
    private const LEVELS = [
        [
            'table' => 'Products_Departments_T',
            'parent' => null,
            'source' => 'Source_Main_Sequence',
            'index' => 'ux_pd_display_order',
        ],
        [
            'table' => 'Products_Sub_Department_T',
            'parent' => 'Products_Departments_Id',
            'source' => 'Source_Sub_Sequence',
            'index' => 'ux_psd_parent_display_order',
        ],
        [
            'table' => 'Products_Sub_Sub_Department_T',
            'parent' => 'Product_Sub_Department_Id',
            'source' => 'Source_Sub_Sub_Sequence',
            'index' => 'ux_pssd_parent_display_order',
        ],
    ];

    /** @var array<int, array{table: string, name: string, columns: array<int, string>, nullable: ?string}> */
    private const SEARCH_INDEXES = [
        [
            'table' => 'Products_Departments_T',
            'name' => 'idx_pd_display_search_name_ar',
            'columns' => ['Product_Department_Name_Ar'],
            'nullable' => 'Product_Department_Name_Ar',
        ],
        [
            'table' => 'Products_Sub_Department_T',
            'name' => 'idx_psd_display_search_name',
            'columns' => ['Sub_Department_Name'],
            'nullable' => null,
        ],
        [
            'table' => 'Products_Sub_Department_T',
            'name' => 'idx_psd_display_search_name_ar',
            'columns' => ['Sub_Department_Name_Ar'],
            'nullable' => 'Sub_Department_Name_Ar',
        ],
        [
            'table' => 'Products_Sub_Department_T',
            'name' => 'idx_psd_parent_name_ar',
            'columns' => ['Products_Departments_Id', 'Sub_Department_Name_Ar'],
            'nullable' => 'Sub_Department_Name_Ar',
        ],
        [
            'table' => 'Products_Sub_Sub_Department_T',
            'name' => 'idx_pssd_display_search_name',
            'columns' => ['Product_Sub_Sub_Department_Name'],
            'nullable' => null,
        ],
        [
            'table' => 'Products_Sub_Sub_Department_T',
            'name' => 'idx_pssd_display_search_name_ar',
            'columns' => ['Product_Sub_Sub_Department_Name_Ar'],
            'nullable' => 'Product_Sub_Sub_Department_Name_Ar',
        ],
        [
            'table' => 'Products_Sub_Sub_Department_T',
            'name' => 'idx_pssd_parent_name_ar',
            'columns' => ['Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Name_Ar'],
            'nullable' => 'Product_Sub_Sub_Department_Name_Ar',
        ],
    ];

    public function up(): void
    {
        $this->assertHierarchyTablesExist();

        foreach (self::LEVELS as $level) {
            if (! Schema::hasColumn($level['table'], 'Display_Order')) {
                Schema::table($level['table'], function (Blueprint $table): void {
                    $table->bigInteger('Display_Order')->nullable();
                });
            }
        }

        DB::transaction(function (): void {
            $this->backfillDisplayOrder();
        }, 3);

        $this->makeDisplayOrderRequired();
        $this->createDisplayOrderIndexes();
        $this->createSearchIndexes();
        $this->createRevisionState();
    }

    public function down(): void
    {
        $this->dropSearchIndexes();
        $this->dropDisplayOrderIndexes();

        foreach (array_reverse(self::LEVELS) as $level) {
            if (Schema::hasColumn($level['table'], 'Display_Order')) {
                Schema::table($level['table'], function (Blueprint $table): void {
                    $table->dropColumn('Display_Order');
                });
            }
        }

        Schema::dropIfExists(self::STATE_TABLE);
    }

    private function assertHierarchyTablesExist(): void
    {
        foreach (self::LEVELS as $level) {
            if (! Schema::hasTable($level['table'])) {
                throw new RuntimeException("Cannot add display ordering because {$level['table']} does not exist.");
            }
            if (! Schema::hasColumn($level['table'], $level['source'])) {
                throw new RuntimeException("Cannot add display ordering because {$level['table']}.{$level['source']} does not exist.");
            }
            if ($level['parent'] !== null && ! Schema::hasColumn($level['table'], $level['parent'])) {
                throw new RuntimeException("Cannot add display ordering because {$level['table']}.{$level['parent']} does not exist.");
            }
        }
    }

    private function backfillDisplayOrder(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            foreach (self::LEVELS as $level) {
                $this->backfillSqlServerLevel($level);
            }

            return;
        }

        foreach (self::LEVELS as $level) {
            $query = DB::table($level['table'])->select('id', $level['source']);
            if ($level['parent'] !== null) {
                $query->addSelect($level['parent'])->orderBy($level['parent']);
            }
            $rows = $query
                ->orderByRaw("CASE WHEN {$level['source']} IS NULL THEN 1 ELSE 0 END")
                ->orderBy($level['source'])
                ->orderBy('id')
                ->get();

            $currentParent = null;
            $sequence = 0;
            foreach ($rows as $row) {
                $parent = $level['parent'] === null ? '__root__' : (string) $row->{$level['parent']};
                if ($parent !== $currentParent) {
                    $currentParent = $parent;
                    $sequence = 0;
                }
                $sequence++;
                DB::table($level['table'])
                    ->where('id', $row->id)
                    ->update(['Display_Order' => $sequence * self::ORDER_STEP]);
            }
        }
    }

    /** @param array{table: string, parent: ?string, source: string, index: string} $level */
    private function backfillSqlServerLevel(array $level): void
    {
        $table = $this->sqlServerIdentifier($level['table']);
        $source = $this->sqlServerIdentifier($level['source']);
        $partition = $level['parent'] === null
            ? ''
            : 'PARTITION BY '.$this->sqlServerIdentifier($level['parent']).' ';
        $step = self::ORDER_STEP;

        DB::statement(<<<SQL
WITH [ranked] AS (
    SELECT [id],
           ROW_NUMBER() OVER (
               {$partition}ORDER BY CASE WHEN {$source} IS NULL THEN 1 ELSE 0 END, {$source}, [id]
           ) AS [row_number]
    FROM {$table} WITH (UPDLOCK, HOLDLOCK)
)
UPDATE [target]
SET [Display_Order] = CAST([ranked].[row_number] AS BIGINT) * {$step}
FROM {$table} AS [target]
INNER JOIN [ranked] ON [ranked].[id] = [target].[id]
SQL);
    }

    private function makeDisplayOrderRequired(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            foreach (self::LEVELS as $level) {
                $table = $this->sqlServerIdentifier($level['table']);
                DB::statement("ALTER TABLE {$table} ALTER COLUMN [Display_Order] BIGINT NOT NULL");
            }

            return;
        }

        foreach (self::LEVELS as $level) {
            Schema::table($level['table'], function (Blueprint $table): void {
                $table->bigInteger('Display_Order')->nullable(false)->change();
            });
        }
    }

    private function createDisplayOrderIndexes(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX [ux_pd_display_order] ON [Products_Departments_T] ([Display_Order])');
            DB::statement('CREATE UNIQUE INDEX [ux_psd_parent_display_order] ON [Products_Sub_Department_T] ([Products_Departments_Id], [Display_Order])');
            DB::statement('CREATE UNIQUE INDEX [ux_pssd_parent_display_order] ON [Products_Sub_Sub_Department_T] ([Product_Sub_Department_Id], [Display_Order])');

            return;
        }

        Schema::table('Products_Departments_T', fn (Blueprint $table) => $table->unique('Display_Order', 'ux_pd_display_order'));
        Schema::table('Products_Sub_Department_T', fn (Blueprint $table) => $table->unique(['Products_Departments_Id', 'Display_Order'], 'ux_psd_parent_display_order'));
        Schema::table('Products_Sub_Sub_Department_T', fn (Blueprint $table) => $table->unique(['Product_Sub_Department_Id', 'Display_Order'], 'ux_pssd_parent_display_order'));
    }

    private function createSearchIndexes(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            foreach (self::SEARCH_INDEXES as $index) {
                $table = $this->sqlServerIdentifier($index['table']);
                $name = $this->sqlServerIdentifier($index['name']);
                $columns = implode(', ', array_map(
                    fn (string $column): string => $this->sqlServerIdentifier($column),
                    $index['columns'],
                ));
                $filter = $index['nullable'] === null
                    ? ''
                    : ' WHERE '.$this->sqlServerIdentifier($index['nullable']).' IS NOT NULL';

                DB::statement('CREATE INDEX '.$name.' ON '.$table.' ('.$columns.')'.$filter);
            }

            return;
        }

        foreach (self::SEARCH_INDEXES as $index) {
            Schema::table($index['table'], function (Blueprint $table) use ($index): void {
                $table->index($index['columns'], $index['name']);
            });
        }
    }

    private function createRevisionState(): void
    {
        if (! Schema::hasTable(self::STATE_TABLE)) {
            Schema::create(self::STATE_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('Revision')->default(1);
            });
        }

        if (! DB::table(self::STATE_TABLE)->where('id', 1)->exists()) {
            if (DB::table(self::STATE_TABLE)->exists()) {
                throw new RuntimeException('The hierarchy display-order state table must reserve id 1 for the singleton revision.');
            }

            $id = DB::table(self::STATE_TABLE)->insertGetId(['Revision' => 1]);
            if ((int) $id !== 1) {
                throw new RuntimeException('The hierarchy display-order revision singleton was not created with id 1.');
            }
        }
    }

    private function dropSearchIndexes(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            foreach (array_reverse(self::SEARCH_INDEXES) as $index) {
                if (Schema::hasTable($index['table'])) {
                    DB::statement(
                        'DROP INDEX '.$this->sqlServerIdentifier($index['name']).
                        ' ON '.$this->sqlServerIdentifier($index['table'])
                    );
                }
            }

            return;
        }

        foreach (array_reverse(self::SEARCH_INDEXES) as $index) {
            if (Schema::hasTable($index['table'])) {
                Schema::table($index['table'], function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index['name']);
                });
            }
        }
    }

    private function dropDisplayOrderIndexes(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            foreach (self::LEVELS as $level) {
                if (Schema::hasTable($level['table']) && Schema::hasColumn($level['table'], 'Display_Order')) {
                    DB::statement("DROP INDEX [{$level['index']}] ON ".$this->sqlServerIdentifier($level['table']));
                }
            }

            return;
        }

        foreach (self::LEVELS as $level) {
            if (Schema::hasTable($level['table']) && Schema::hasColumn($level['table'], 'Display_Order')) {
                Schema::table($level['table'], fn (Blueprint $table) => $table->dropUnique($level['index']));
            }
        }
    }

    private function sqlServerIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) {
            throw new RuntimeException('Unsafe SQL Server hierarchy identifier.');
        }

        return '['.$identifier.']';
    }
};
