<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKUP_TABLE = 'Product_Hierarchy_Code_Recode_Backup_T';

    private const MIGRATION_KEY = '2026-08-flat-database-codes';

    private const CODE_PERIOD = '2026-08';

    private const PERIOD_SEGMENT = '2026_AUG';

    private const TEMPORARY_PREFIX = '__PHR_20260807_';

    private const MAX_SEQUENCE = 999999;

    /** @var array<string, array{table: string, column: string, marker: string}> */
    private const ENTITIES = [
        'department' => [
            'table' => 'Products_Departments_T',
            'column' => 'Product_Department_Code',
            'marker' => 'D',
        ],
        'sub_department' => [
            'table' => 'Products_Sub_Department_T',
            'column' => 'Products_Sub_Department_Code',
            'marker' => 'S',
        ],
        'sub_sub_department' => [
            'table' => 'Products_Sub_Sub_Department_T',
            'column' => 'Product_Sub_Sub_Department_Code',
            'marker' => 'L',
        ],
    ];

    public function up(): void
    {
        $this->assertRequiredSchema();

        DB::transaction(function (): void {
            $this->acquireHierarchyLock();

            $plan = $this->buildPlan($this->lockHierarchyRows());
            $this->ensureBackupTable();
            $backup = $this->prepareOrValidateBackup($plan);

            if ($this->allRowsAlreadyUseTargets($plan, $backup)) {
                return;
            }

            $this->assertTemporaryNamespaceAvailable($plan);
            $this->stageCodes($plan, 'UP');
            $this->writePlanCodes($plan, 'target_code');
        }, 3);
    }

    public function down(): void
    {
        $this->assertRequiredSchema();
        if (! Schema::hasTable(self::BACKUP_TABLE)) {
            throw new RuntimeException('Cannot restore hierarchy codes because the recode backup table is missing.');
        }

        DB::transaction(function (): void {
            $this->acquireHierarchyLock();

            $plan = $this->buildPlan($this->lockHierarchyRows());
            $backup = $this->validatedBackup($plan);

            foreach ($plan as $item) {
                $key = $this->planKey($item['entity_type'], $item['entity_id']);
                if ($item['current_code'] !== $item['target_code']) {
                    throw new RuntimeException(
                        "Cannot restore hierarchy codes because {$key} no longer contains the migrated target code."
                    );
                }
                if (! array_key_exists($key, $backup)) {
                    throw new RuntimeException("Cannot restore hierarchy codes because the backup for {$key} is missing.");
                }
            }

            $this->assertTemporaryNamespaceAvailable($plan);
            $this->stageCodes($plan, 'DOWN');

            foreach ($plan as $item) {
                $key = $this->planKey($item['entity_type'], $item['entity_id']);
                $this->updateCode($item, $backup[$key]['original_code']);
            }

            Schema::drop(self::BACKUP_TABLE);
        }, 3);
    }

    private function assertRequiredSchema(): void
    {
        $required = [
            'Products_Departments_T' => [
                'id',
                'Product_Department_Code',
                'Source_Main_Id',
                'Source_Main_Sequence',
                'Hierarchy_Code_Period',
            ],
            'Products_Sub_Department_T' => [
                'id',
                'Products_Departments_Id',
                'Products_Sub_Department_Code',
                'Source_Sub_Sequence',
            ],
            'Products_Sub_Sub_Department_T' => [
                'id',
                'Product_Sub_Department_Id',
                'Product_Sub_Sub_Department_Code',
                'Source_Sub_Sub_Sequence',
            ],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cannot recode hierarchy data because {$table} does not exist.");
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Cannot recode hierarchy data because {$table}.{$column} does not exist.");
                }
            }
        }
    }

    private function acquireHierarchyLock(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        $result = DB::selectOne(<<<'SQL'
DECLARE @result INT;
EXEC @result = sp_getapplock
    @Resource = 'product-hierarchy-import',
    @LockMode = 'Exclusive',
    @LockOwner = 'Transaction',
    @LockTimeout = 30000;
SELECT @result AS result;
SQL);

        if (! $result || (int) $result->result < 0) {
            throw new RuntimeException('Could not acquire the product hierarchy lock for database recoding.');
        }
    }

    /** @return array{departments: array<int, object>, sub_departments: array<int, object>, sub_sub_departments: array<int, object>} */
    private function lockHierarchyRows(): array
    {
        return [
            'departments' => DB::table('Products_Departments_T')
                ->select([
                    'id',
                    'Product_Department_Code',
                    'Source_Main_Id',
                    'Source_Main_Sequence',
                    'Hierarchy_Code_Period',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all(),
            'sub_departments' => DB::table('Products_Sub_Department_T')
                ->select([
                    'id',
                    'Products_Departments_Id',
                    'Products_Sub_Department_Code',
                    'Source_Sub_Sequence',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all(),
            'sub_sub_departments' => DB::table('Products_Sub_Sub_Department_T')
                ->select([
                    'id',
                    'Product_Sub_Department_Id',
                    'Product_Sub_Sub_Department_Code',
                    'Source_Sub_Sub_Sequence',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all(),
        ];
    }

    /**
     * Database child codes intentionally use table-global sequence numbers for the
     * fixed period. Parent-local source sequences remain untouched for Excel export.
     *
     * @param  array{departments: array<int, object>, sub_departments: array<int, object>, sub_sub_departments: array<int, object>}  $rows
     * @return array<int, array{entity_type: string, entity_id: int, table: string, column: string, marker: string, current_code: ?string, target_code: string}>
     */
    private function buildPlan(array $rows): array
    {
        $departments = [];
        $departmentSequences = [];

        foreach ($rows['departments'] as $row) {
            $id = $this->positiveId($row->id, 'department');
            $sequence = $this->requiredSequence($row->Source_Main_Sequence, "Source_Main_Sequence on department {$id}");
            $sourceMainId = trim((string) $row->Source_Main_Id);

            if (! preg_match('/^MAIN-(\d{4,6})$/D', $sourceMainId, $matches)) {
                throw new RuntimeException("Department {$id} must have Source_Main_Id in MAIN-0001 format.");
            }
            if ((int) $matches[1] !== $sequence) {
                throw new RuntimeException("Department {$id} has conflicting Source_Main_Id and Source_Main_Sequence metadata.");
            }
            if ((string) $row->Hierarchy_Code_Period !== self::CODE_PERIOD) {
                throw new RuntimeException("Department {$id} must have Hierarchy_Code_Period ".self::CODE_PERIOD.'.');
            }
            if (isset($departmentSequences[$sequence])) {
                throw new RuntimeException("Departments {$departmentSequences[$sequence]} and {$id} share Source_Main_Sequence {$sequence}.");
            }

            $departmentSequences[$sequence] = $id;
            $departments[$id] = [
                'id' => $id,
                'main_sequence' => $sequence,
                'current_code' => $this->code($row->Product_Department_Code),
            ];
        }

        $subDepartments = [];
        $subSequenceOwners = [];
        foreach ($rows['sub_departments'] as $row) {
            $id = $this->positiveId($row->id, 'sub-department');
            $departmentId = $this->positiveId($row->Products_Departments_Id, "parent department for sub-department {$id}");
            if (! isset($departments[$departmentId])) {
                throw new RuntimeException("Sub-department {$id} references missing department {$departmentId}.");
            }

            $sequence = $this->requiredSequence($row->Source_Sub_Sequence, "Source_Sub_Sequence on sub-department {$id}");
            $sequenceKey = $departmentId.':'.$sequence;
            if (isset($subSequenceOwners[$sequenceKey])) {
                throw new RuntimeException(
                    "Sub-departments {$subSequenceOwners[$sequenceKey]} and {$id} share source sequence {$sequence} under department {$departmentId}."
                );
            }

            $subSequenceOwners[$sequenceKey] = $id;
            $subDepartments[$id] = [
                'id' => $id,
                'department_id' => $departmentId,
                'main_sequence' => $departments[$departmentId]['main_sequence'],
                'sub_sequence' => $sequence,
                'current_code' => $this->code($row->Products_Sub_Department_Code),
            ];
        }

        $subSubDepartments = [];
        $leafSequenceOwners = [];
        foreach ($rows['sub_sub_departments'] as $row) {
            $id = $this->positiveId($row->id, 'sub-sub-department');
            $subDepartmentId = $this->positiveId(
                $row->Product_Sub_Department_Id,
                "parent sub-department for sub-sub-department {$id}",
            );
            if (! isset($subDepartments[$subDepartmentId])) {
                throw new RuntimeException("Sub-sub-department {$id} references missing sub-department {$subDepartmentId}.");
            }

            $sequence = $this->requiredSequence(
                $row->Source_Sub_Sub_Sequence,
                "Source_Sub_Sub_Sequence on sub-sub-department {$id}",
            );
            $sequenceKey = $subDepartmentId.':'.$sequence;
            if (isset($leafSequenceOwners[$sequenceKey])) {
                throw new RuntimeException(
                    "Sub-sub-departments {$leafSequenceOwners[$sequenceKey]} and {$id} share source sequence {$sequence} under sub-department {$subDepartmentId}."
                );
            }

            $leafSequenceOwners[$sequenceKey] = $id;
            $parent = $subDepartments[$subDepartmentId];
            $subSubDepartments[$id] = [
                'id' => $id,
                'sub_department_id' => $subDepartmentId,
                'main_sequence' => $parent['main_sequence'],
                'sub_sequence' => $parent['sub_sequence'],
                'sub_sub_sequence' => $sequence,
                'current_code' => $this->code($row->Product_Sub_Sub_Department_Code),
            ];
        }

        $departments = array_values($departments);
        $subDepartments = array_values($subDepartments);
        $subSubDepartments = array_values($subSubDepartments);

        usort($departments, fn (array $left, array $right): int => $this->compareSequenceTuple(
            [$left['main_sequence'], $left['id']],
            [$right['main_sequence'], $right['id']],
        ));
        usort($subDepartments, fn (array $left, array $right): int => $this->compareSequenceTuple(
            [$left['main_sequence'], $left['sub_sequence'], $left['id']],
            [$right['main_sequence'], $right['sub_sequence'], $right['id']],
        ));
        usort($subSubDepartments, fn (array $left, array $right): int => $this->compareSequenceTuple(
            [$left['main_sequence'], $left['sub_sequence'], $left['sub_sub_sequence'], $left['id']],
            [$right['main_sequence'], $right['sub_sequence'], $right['sub_sub_sequence'], $right['id']],
        ));

        if (count($subDepartments) > self::MAX_SEQUENCE) {
            throw new RuntimeException('There are too many sub-departments for six-digit database codes.');
        }
        if (count($subSubDepartments) > self::MAX_SEQUENCE) {
            throw new RuntimeException('There are too many sub-sub-departments for six-digit database codes.');
        }

        $plan = [];
        foreach ($departments as $department) {
            $plan[] = $this->planItem(
                'department',
                $department['id'],
                $department['current_code'],
                'DEPT_'.self::PERIOD_SEGMENT.'_MAIN_'.$this->formatSequence($department['main_sequence']),
            );
        }
        foreach ($subDepartments as $index => $subDepartment) {
            $plan[] = $this->planItem(
                'sub_department',
                $subDepartment['id'],
                $subDepartment['current_code'],
                'SUBDEPT_'.self::PERIOD_SEGMENT.'_SUB_'.$this->formatSequence($index + 1),
            );
        }
        foreach ($subSubDepartments as $index => $subSubDepartment) {
            $plan[] = $this->planItem(
                'sub_sub_department',
                $subSubDepartment['id'],
                $subSubDepartment['current_code'],
                'SUBSUBDEPT_'.self::PERIOD_SEGMENT.'_SUBSUB_'.$this->formatSequence($index + 1),
            );
        }

        $this->assertUniqueTargets($plan);

        return $plan;
    }

    /**
     * @return array{entity_type: string, entity_id: int, table: string, column: string, marker: string, current_code: ?string, target_code: string}
     */
    private function planItem(string $entityType, int $entityId, ?string $currentCode, string $targetCode): array
    {
        $definition = self::ENTITIES[$entityType];

        if (strlen($targetCode) > 100) {
            throw new RuntimeException("Generated hierarchy code {$targetCode} exceeds the database column length.");
        }

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'table' => $definition['table'],
            'column' => $definition['column'],
            'marker' => $definition['marker'],
            'current_code' => $currentCode,
            'target_code' => $targetCode,
        ];
    }

    /** @param array<int, array{entity_type: string, target_code: string, entity_id: int}> $plan */
    private function assertUniqueTargets(array $plan): void
    {
        $owners = [];
        foreach ($plan as $item) {
            $key = $item['entity_type'].':'.strtoupper($item['target_code']);
            if (isset($owners[$key])) {
                throw new RuntimeException(
                    "Generated code {$item['target_code']} would be shared by hierarchy records {$owners[$key]} and {$item['entity_id']}."
                );
            }
            $owners[$key] = $item['entity_id'];
        }
    }

    private function ensureBackupTable(): void
    {
        if (Schema::hasTable(self::BACKUP_TABLE)) {
            return;
        }

        Schema::create(self::BACKUP_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('Migration_Key', 64);
            $table->string('Entity_Type', 32);
            $table->unsignedBigInteger('Entity_Id');
            $table->string('Original_Code', 100)->nullable();
            $table->string('Target_Code', 100);
            $table->dateTime('Captured_At');
            $table->unique(['Migration_Key', 'Entity_Type', 'Entity_Id'], 'ux_phcrb_migration_entity');
        });
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, current_code: ?string, target_code: string}>  $plan
     * @return array<string, array{original_code: ?string, target_code: string}>
     */
    private function prepareOrValidateBackup(array $plan): array
    {
        $existing = DB::table(self::BACKUP_TABLE)
            ->where('Migration_Key', self::MIGRATION_KEY)
            ->orderBy('id')
            ->get(['Entity_Type', 'Entity_Id', 'Original_Code', 'Target_Code']);

        $otherRows = DB::table(self::BACKUP_TABLE)
            ->where('Migration_Key', '<>', self::MIGRATION_KEY)
            ->exists();
        if ($otherRows) {
            throw new RuntimeException('The hierarchy recode backup table contains an unexpected migration key.');
        }

        if ($existing->isEmpty() && $plan !== []) {
            $capturedAt = now();
            foreach (array_chunk($plan, 200) as $chunk) {
                DB::table(self::BACKUP_TABLE)->insert(array_map(
                    static fn (array $item): array => [
                        'Migration_Key' => self::MIGRATION_KEY,
                        'Entity_Type' => $item['entity_type'],
                        'Entity_Id' => $item['entity_id'],
                        'Original_Code' => $item['current_code'],
                        'Target_Code' => $item['target_code'],
                        'Captured_At' => $capturedAt,
                    ],
                    $chunk,
                ));
            }

            $existing = DB::table(self::BACKUP_TABLE)
                ->where('Migration_Key', self::MIGRATION_KEY)
                ->orderBy('id')
                ->get(['Entity_Type', 'Entity_Id', 'Original_Code', 'Target_Code']);
        }

        return $this->backupByPlanKey($plan, $existing->all());
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, target_code: string}>  $plan
     * @return array<string, array{original_code: ?string, target_code: string}>
     */
    private function validatedBackup(array $plan): array
    {
        $unexpected = DB::table(self::BACKUP_TABLE)
            ->where('Migration_Key', '<>', self::MIGRATION_KEY)
            ->exists();
        if ($unexpected) {
            throw new RuntimeException('The hierarchy recode backup table contains an unexpected migration key.');
        }

        return $this->backupByPlanKey(
            $plan,
            DB::table(self::BACKUP_TABLE)
                ->where('Migration_Key', self::MIGRATION_KEY)
                ->orderBy('id')
                ->get(['Entity_Type', 'Entity_Id', 'Original_Code', 'Target_Code'])
                ->all(),
        );
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, target_code: string}>  $plan
     * @param  array<int, object>  $rows
     * @return array<string, array{original_code: ?string, target_code: string}>
     */
    private function backupByPlanKey(array $plan, array $rows): array
    {
        $backup = [];
        foreach ($rows as $row) {
            $entityType = (string) $row->Entity_Type;
            $entityId = (int) $row->Entity_Id;
            if (! isset(self::ENTITIES[$entityType]) || $entityId < 1) {
                throw new RuntimeException('The hierarchy recode backup contains an invalid entity identity.');
            }

            $key = $this->planKey($entityType, $entityId);
            if (isset($backup[$key])) {
                throw new RuntimeException("The hierarchy recode backup contains duplicate record {$key}.");
            }
            $backup[$key] = [
                'original_code' => $this->code($row->Original_Code),
                'target_code' => (string) $row->Target_Code,
            ];
        }

        if (count($backup) !== count($plan)) {
            throw new RuntimeException('The hierarchy recode backup does not cover the exact current hierarchy.');
        }

        foreach ($plan as $item) {
            $key = $this->planKey($item['entity_type'], $item['entity_id']);
            if (! isset($backup[$key])) {
                throw new RuntimeException("The hierarchy recode backup is missing {$key}.");
            }
            if ($backup[$key]['target_code'] !== $item['target_code']) {
                throw new RuntimeException("The hierarchy recode target for {$key} no longer matches its backup.");
            }
        }

        return $backup;
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, current_code: ?string, target_code: string}>  $plan
     * @param  array<string, array{original_code: ?string, target_code: string}>  $backup
     */
    private function allRowsAlreadyUseTargets(array $plan, array $backup): bool
    {
        $allTargets = true;

        foreach ($plan as $item) {
            $key = $this->planKey($item['entity_type'], $item['entity_id']);
            if ($item['current_code'] === $item['target_code']) {
                continue;
            }

            $allTargets = false;
            if ($item['current_code'] !== $backup[$key]['original_code']) {
                throw new RuntimeException(
                    "Cannot recode hierarchy data because {$key} changed after its original code was backed up."
                );
            }
        }

        return $allTargets;
    }

    /** @param array<int, array{current_code: ?string, entity_type: string, entity_id: int}> $plan */
    private function assertTemporaryNamespaceAvailable(array $plan): void
    {
        foreach ($plan as $item) {
            if ($item['current_code'] !== null && str_starts_with($item['current_code'], self::TEMPORARY_PREFIX)) {
                $key = $this->planKey($item['entity_type'], $item['entity_id']);
                throw new RuntimeException("Cannot recode hierarchy data because {$key} uses the reserved migration prefix.");
            }
        }
    }

    /** @param array<int, array{entity_id: int, table: string, column: string, marker: string}> $plan */
    private function stageCodes(array $plan, string $direction): void
    {
        foreach ($plan as $item) {
            $temporary = self::TEMPORARY_PREFIX.$direction.'_'.$item['marker'].'_'.$item['entity_id'];
            $this->updateCode($item, $temporary);
        }
    }

    /** @param array<int, array{target_code: string, table: string, column: string, entity_id: int}> $plan */
    private function writePlanCodes(array $plan, string $field): void
    {
        foreach ($plan as $item) {
            $this->updateCode($item, $item[$field]);
        }
    }

    /** @param array{table: string, column: string, entity_id: int} $item */
    private function updateCode(array $item, ?string $code): void
    {
        $updated = DB::table($item['table'])
            ->where('id', $item['entity_id'])
            ->update([$item['column'] => $code]);

        if ($updated !== 1) {
            throw new RuntimeException(
                "Hierarchy record {$item['table']}:{$item['entity_id']} changed while its code was being migrated."
            );
        }
    }

    private function positiveId(mixed $value, string $label): int
    {
        if (! is_int($value) && ! ctype_digit((string) $value)) {
            throw new RuntimeException("The {$label} ID must be a positive integer.");
        }

        $id = (int) $value;
        if ($id < 1) {
            throw new RuntimeException("The {$label} ID must be a positive integer.");
        }

        return $id;
    }

    private function requiredSequence(mixed $value, string $label): int
    {
        if (! is_int($value) && ! ctype_digit((string) $value)) {
            throw new RuntimeException("{$label} is required and must be an integer.");
        }

        $sequence = (int) $value;
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new RuntimeException("{$label} must be between 1 and ".self::MAX_SEQUENCE.'.');
        }

        return $sequence;
    }

    private function formatSequence(int $sequence): string
    {
        return str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** @param array<int, int> $left @param array<int, int> $right */
    private function compareSequenceTuple(array $left, array $right): int
    {
        foreach ($left as $index => $value) {
            $comparison = $value <=> $right[$index];
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function code(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function planKey(string $entityType, int $entityId): string
    {
        return $entityType.':'.$entityId;
    }
};
