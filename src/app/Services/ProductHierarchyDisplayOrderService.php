<?php

namespace App\Services;

use App\Models\ProductDepartments;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductHierarchyDisplayOrderService
{
    public const ORDER_STEP = 1_000_000_000;

    private const STATE_TABLE = 'Product_Hierarchy_Display_Order_State_T';

    private const STATE_ID = 1;

    /** @var array<string, array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string}> */
    private const LEVELS = [
        'department' => [
            'model' => ProductDepartments::class,
            'table' => 'Products_Departments_T',
            'parent' => null,
            'source' => 'Source_Main_Sequence',
            'code' => 'Product_Department_Code',
            'name' => 'Product_Department_Name',
            'name_ar' => 'Product_Department_Name_Ar',
            'child_relation' => 'subDepartments',
        ],
        'sub_department' => [
            'model' => ProductSubDepartment::class,
            'table' => 'Products_Sub_Department_T',
            'parent' => 'Products_Departments_Id',
            'source' => 'Source_Sub_Sequence',
            'code' => 'Products_Sub_Department_Code',
            'name' => 'Sub_Department_Name',
            'name_ar' => 'Sub_Department_Name_Ar',
            'child_relation' => 'subSubDepartments',
        ],
        'sub_sub_department' => [
            'model' => ProductSubSubDepartment::class,
            'table' => 'Products_Sub_Sub_Department_T',
            'parent' => 'Product_Sub_Department_Id',
            'source' => 'Source_Sub_Sub_Sequence',
            'code' => 'Product_Sub_Sub_Department_Code',
            'name' => 'Product_Sub_Sub_Department_Name',
            'name_ar' => 'Product_Sub_Sub_Department_Name_Ar',
            'child_relation' => null,
        ],
    ];

    public function paginate(
        string $level,
        ?int $parentId,
        ?string $search,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $definition = $this->definition($level);
        $this->validateParentScope($definition, $parentId);

        $query = $this->scopeQuery($definition, $parentId)
            ->select($this->selectColumns($definition));
        $this->addChildCount($query, $definition);
        $this->addSearch($query, $definition, $search);
        if ($level === 'sub_department') {
            $query->with('productDepartment:id,Product_Department_Name');
        } elseif ($level === 'sub_sub_department') {
            $query->with('subDepartment:id,Products_Departments_Id,Sub_Department_Name');
            $query->with('subDepartment.productDepartment:id,Product_Department_Name');
        }

        return $query
            ->orderBy('Display_Order')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $search, int $perLevel): array
    {
        $results = [];
        $prefix = self::prefixSearchPattern($search);

        $departments = ProductDepartments::query()
            ->select($this->selectColumns($this->definition('department')))
            ->withCount(['subDepartments as child_count'])
            ->where(function (Builder $query) use ($search): void {
                $this->addSearch($query, $this->definition('department'), $search);
            })
            ->orderBy('Display_Order')
            ->orderBy('id')
            ->limit($perLevel)
            ->get();
        foreach ($departments as $department) {
            $results[] = $this->present('department', $department);
        }

        $subDepartments = ProductSubDepartment::query()
            ->select($this->selectColumns($this->definition('sub_department')))
            ->withCount(['subSubDepartments as child_count'])
            ->with('productDepartment:id,Product_Department_Name')
            ->where(function (Builder $query) use ($search, $prefix): void {
                $this->addSearch($query, $this->definition('sub_department'), $search);
                $query->orWhereHas('productDepartment', function (Builder $parent) use ($prefix): void {
                    $this->addPrefixCondition($parent, 'Product_Department_Name', $prefix);
                });
            })
            ->orderBy('Display_Order')
            ->orderBy('id')
            ->limit($perLevel)
            ->get();
        foreach ($subDepartments as $subDepartment) {
            $results[] = $this->present('sub_department', $subDepartment);
        }

        $leaves = ProductSubSubDepartment::query()
            ->select($this->selectColumns($this->definition('sub_sub_department')))
            ->with('subDepartment:id,Products_Departments_Id,Sub_Department_Name')
            ->with('subDepartment.productDepartment:id,Product_Department_Name')
            ->where(function (Builder $query) use ($search, $prefix): void {
                $this->addSearch($query, $this->definition('sub_sub_department'), $search);
                $query->orWhereHas('subDepartment', function (Builder $parent) use ($prefix): void {
                    $this->addPrefixCondition($parent, 'Sub_Department_Name', $prefix);
                    $parent->orWhereHas('productDepartment', function (Builder $department) use ($prefix): void {
                        $this->addPrefixCondition($department, 'Product_Department_Name', $prefix);
                    });
                });
            })
            ->orderBy('Display_Order')
            ->orderBy('id')
            ->limit($perLevel)
            ->get();
        foreach ($leaves as $leaf) {
            $leaf->setAttribute('child_count', 0);
            $results[] = $this->present('sub_sub_department', $leaf);
        }

        return $results;
    }

    /** @return array{item: array<string, mixed>, revision: int, moved: bool} */
    public function moveBefore(string $level, int $id, ?int $beforeId, int $expectedRevision): array
    {
        $definition = $this->definition($level);

        return DB::transaction(function () use ($level, $definition, $id, $beforeId, $expectedRevision): array {
            $this->acquireHierarchyLock();
            $currentRevision = $this->lockRevisionState();
            if ($currentRevision !== $expectedRevision) {
                throw new RuntimeException(
                    'The hierarchy order changed after it was loaded. Refresh the list and try again.',
                    409,
                );
            }

            $moving = $definition['model']::query()->lockForUpdate()->find($id);
            if (! $moving) {
                throw new RuntimeException('The category to move was not found.', 404);
            }

            $parentId = $this->modelParentId($moving, $definition);
            $anchor = null;
            if ($beforeId !== null) {
                if ($beforeId === $id) {
                    return $this->moveResult($level, $moving, false, $currentRevision);
                }

                $anchor = $this->scopeQuery($definition, $parentId)
                    ->lockForUpdate()
                    ->find($beforeId);
                if (! $anchor) {
                    throw new RuntimeException('The target category must belong to the same parent.', 422);
                }
            }

            if ($this->isAlreadyPlaced($definition, $moving, $parentId, $anchor)) {
                return $this->moveResult($level, $moving, false, $currentRevision);
            }

            $target = $this->targetRank($definition, $moving, $parentId, $anchor);
            if ($target === null) {
                $this->compactScope($definition, $parentId);
                $moving->refresh();
                $anchor?->refresh();
                $target = $this->targetRank($definition, $moving, $parentId, $anchor);
            }
            if ($target === null) {
                throw new RuntimeException('The category order could not allocate a new position.', 409);
            }

            $moving->Display_Order = $target;
            $moving->saveQuietly();
            $revision = $this->incrementRevision();

            return $this->moveResult($level, $moving->refresh(), true, $revision);
        }, 3);
    }

    /** @return array{revision: int, changed: bool, updated_count: int} */
    public function resetToDefault(
        string $level,
        ?int $parentId,
        int $expectedRevision,
    ): array {
        $definition = $this->definition($level);
        $this->validateParentScope($definition, $parentId);

        return DB::transaction(function () use (
            $level,
            $definition,
            $parentId,
            $expectedRevision,
        ): array {
            $this->acquireHierarchyLock();
            $currentRevision = $this->lockRevisionState();
            if ($currentRevision !== $expectedRevision) {
                throw new RuntimeException(
                    'The hierarchy order changed after it was loaded. Refresh the list and try again.',
                    409,
                );
            }

            $this->validateParentExists($level, $parentId);
            $count = (int) $this->scopeQuery($definition, $parentId)->count();
            if ($count > intdiv(PHP_INT_MAX, self::ORDER_STEP)) {
                throw new RuntimeException('This hierarchy scope is too large to reset safely.', 422);
            }
            if ($count === 0) {
                return [
                    'revision' => $currentRevision,
                    'changed' => false,
                    'updated_count' => 0,
                ];
            }

            $updatedCount = DB::connection()->getDriverName() === 'sqlsrv'
                ? $this->resetSqlServerScope($definition, $parentId, $count)
                : $this->resetPortableScope($definition, $parentId);
            if ($updatedCount === 0) {
                return [
                    'revision' => $currentRevision,
                    'changed' => false,
                    'updated_count' => 0,
                ];
            }

            return [
                'revision' => $this->incrementRevision(),
                'changed' => true,
                'updated_count' => $updatedCount,
            ];
        }, 3);
    }

    public function currentRevision(): int
    {
        return (int) (DB::table(self::STATE_TABLE)->where('id', self::STATE_ID)->value('Revision') ?? 1);
    }

    public function lockRevisionState(): int
    {
        $state = DB::table(self::STATE_TABLE)
            ->where('id', self::STATE_ID)
            ->lockForUpdate()
            ->first();
        if (! $state) {
            throw new RuntimeException('The hierarchy display-order revision state is missing.', 500);
        }

        return (int) $state->Revision;
    }

    public function incrementRevision(): int
    {
        $revision = $this->lockRevisionState();
        if ($revision < 1 || $revision >= PHP_INT_MAX) {
            throw new RuntimeException('The hierarchy display-order revision is invalid.', 500);
        }

        DB::table(self::STATE_TABLE)
            ->where('id', self::STATE_ID)
            ->update(['Revision' => $revision + 1]);

        return $revision + 1;
    }

    public function nextAppendOrder(string $level, ?int $parentId): int
    {
        $definition = $this->definition($level);
        $this->validateParentScope($definition, $parentId);
        $maximum = $this->scopeQuery($definition, $parentId)
            ->lockForUpdate()
            ->max('Display_Order');

        return self::appendRank($maximum === null ? null : (int) $maximum);
    }

    public static function midpointRank(int $lower, int $upper): ?int
    {
        if ($lower < 0 || $upper <= $lower || $upper - $lower <= 1) {
            return null;
        }

        return $lower + intdiv($upper - $lower, 2);
    }

    public static function appendRank(?int $last): int
    {
        if ($last === null) {
            return self::ORDER_STEP;
        }
        if ($last < 0 || $last > PHP_INT_MAX - self::ORDER_STEP) {
            throw new RuntimeException('No more hierarchy display-order positions are available.', 422);
        }

        return $last + self::ORDER_STEP;
    }

    /**
     * Build a literal, indexable starts-with LIKE pattern using ! as the escape character.
     */
    public static function prefixSearchPattern(string $search): string
    {
        $search = trim($search);

        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $search,
        ).'%';
    }

    /** @return array<string, mixed> */
    public function present(string $level, Model $model): array
    {
        $definition = $this->definition($level);
        $departmentId = null;
        $departmentName = null;
        $subDepartmentId = null;
        $subDepartmentName = null;

        if ($level === 'department') {
            $departmentId = (int) $model->id;
            $departmentName = $model->{$definition['name']};
        } elseif ($level === 'sub_department') {
            $departmentId = (int) $model->Products_Departments_Id;
            $departmentName = $model->relationLoaded('productDepartment')
                ? $model->productDepartment?->Product_Department_Name
                : null;
            $subDepartmentId = (int) $model->id;
            $subDepartmentName = $model->{$definition['name']};
        } else {
            $subDepartmentId = (int) $model->Product_Sub_Department_Id;
            if ($model->relationLoaded('subDepartment')) {
                $subDepartmentName = $model->subDepartment?->Sub_Department_Name;
                $departmentId = $model->subDepartment?->Products_Departments_Id === null
                    ? null
                    : (int) $model->subDepartment->Products_Departments_Id;
                $departmentName = $model->subDepartment?->relationLoaded('productDepartment')
                    ? $model->subDepartment->productDepartment?->Product_Department_Name
                    : null;
            }
        }

        return [
            'level' => $level,
            'id' => (int) $model->id,
            'code' => $model->{$definition['code']},
            'name' => $model->{$definition['name']},
            'name_ar' => $model->{$definition['name_ar']},
            'display_order' => (int) $model->Display_Order,
            'child_count' => (int) ($model->child_count ?? 0),
            'department_id' => $departmentId,
            'department_name' => $departmentName,
            'sub_department_id' => $subDepartmentId,
            'sub_department_name' => $subDepartmentName,
        ];
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function scopeQuery(array $definition, ?int $parentId): Builder
    {
        $query = $definition['model']::query();
        if ($definition['parent'] !== null) {
            $query->where($definition['parent'], $parentId);
        }

        return $query;
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function validateParentScope(array $definition, ?int $parentId): void
    {
        if ($definition['parent'] === null && $parentId !== null) {
            throw new RuntimeException('Departments do not accept a parent_id.', 422);
        }
        if ($definition['parent'] !== null && ($parentId === null || $parentId < 1)) {
            throw new RuntimeException('A positive parent_id is required for this hierarchy level.', 422);
        }
    }

    private function validateParentExists(string $level, ?int $parentId): void
    {
        $parentModel = match ($level) {
            'sub_department' => ProductDepartments::class,
            'sub_sub_department' => ProductSubDepartment::class,
            default => null,
        };
        if ($parentModel === null) {
            return;
        }

        $parent = $parentModel::query()
            ->select('id')
            ->lockForUpdate()
            ->find($parentId);
        if (! $parent) {
            throw new RuntimeException('The parent category was not found.', 404);
        }
    }

    /** @return array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} */
    private function definition(string $level): array
    {
        if (! isset(self::LEVELS[$level])) {
            throw new RuntimeException('The hierarchy level is invalid.', 422);
        }

        return self::LEVELS[$level];
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function selectColumns(array $definition): array
    {
        return array_values(array_filter([
            'id',
            $definition['parent'],
            $definition['code'],
            $definition['name'],
            $definition['name_ar'],
            'Display_Order',
        ]));
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function addChildCount(Builder $query, array $definition): void
    {
        if ($definition['child_relation'] !== null) {
            $query->withCount([$definition['child_relation'].' as child_count']);
        } else {
            $query->selectRaw('0 AS child_count');
        }
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function addSearch(Builder $query, array $definition, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $prefix = self::prefixSearchPattern($search);
        $query->where(function (Builder $searchQuery) use ($definition, $prefix): void {
            $this->addPrefixCondition($searchQuery, $definition['name'], $prefix);
            $this->addPrefixCondition($searchQuery, $definition['name_ar'], $prefix, 'or');
            $this->addPrefixCondition($searchQuery, $definition['code'], $prefix, 'or');
        });
    }

    private function addPrefixCondition(
        Builder $query,
        string $column,
        string $prefix,
        string $boolean = 'and',
    ): void {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $query->whereRaw($wrapped.' LIKE ? ESCAPE \'!\'', [$prefix], $boolean);
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function modelParentId(Model $model, array $definition): ?int
    {
        return $definition['parent'] === null ? null : (int) $model->{$definition['parent']};
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function isAlreadyPlaced(array $definition, Model $moving, ?int $parentId, ?Model $anchor): bool
    {
        $successor = $this->scopeQuery($definition, $parentId)
            ->where('Display_Order', '>', (int) $moving->Display_Order)
            ->orderBy('Display_Order')
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']);

        if ($anchor === null) {
            return $successor === null;
        }

        return $successor !== null && (int) $successor->id === (int) $anchor->id;
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function targetRank(array $definition, Model $moving, ?int $parentId, ?Model $anchor): ?int
    {
        if ($anchor === null) {
            $last = $this->scopeQuery($definition, $parentId)
                ->whereKeyNot($moving->getKey())
                ->orderByDesc('Display_Order')
                ->lockForUpdate()
                ->first(['Display_Order']);

            return self::appendRank($last === null ? null : (int) $last->Display_Order);
        }

        $upper = (int) $anchor->Display_Order;
        $predecessor = $this->scopeQuery($definition, $parentId)
            ->whereKeyNot($moving->getKey())
            ->where('Display_Order', '<', $upper)
            ->orderByDesc('Display_Order')
            ->lockForUpdate()
            ->first(['Display_Order']);
        $lower = $predecessor === null ? 0 : (int) $predecessor->Display_Order;

        return self::midpointRank($lower, $upper);
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function compactScope(array $definition, ?int $parentId): void
    {
        $count = $this->scopeQuery($definition, $parentId)->lockForUpdate()->count();
        if ($count > intdiv(PHP_INT_MAX, self::ORDER_STEP)) {
            throw new RuntimeException('This hierarchy scope is too large to compact safely.', 422);
        }

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            $this->compactSqlServerScope($definition, $parentId);

            return;
        }

        $ids = $this->scopeQuery($definition, $parentId)
            ->orderBy('Display_Order')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
        foreach ($ids as $index => $id) {
            DB::table($definition['table'])->where('id', $id)->update([
                'Display_Order' => -($index + 1),
            ]);
        }
        foreach ($ids as $index => $id) {
            DB::table($definition['table'])->where('id', $id)->update([
                'Display_Order' => ($index + 1) * self::ORDER_STEP,
            ]);
        }
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function compactSqlServerScope(array $definition, ?int $parentId): void
    {
        $table = $this->sqlServerIdentifier($definition['table']);
        $where = $definition['parent'] === null
            ? ''
            : 'WHERE '.$this->sqlServerIdentifier($definition['parent']).' = ?';
        $bindings = $definition['parent'] === null ? [] : [$parentId];

        DB::statement(<<<SQL
WITH [ranked] AS (
    SELECT [id], ROW_NUMBER() OVER (ORDER BY [Display_Order], [id]) AS [row_number]
    FROM {$table} WITH (UPDLOCK, HOLDLOCK)
    {$where}
)
UPDATE [target]
SET [Display_Order] = -CAST([ranked].[row_number] AS BIGINT)
FROM {$table} AS [target]
INNER JOIN [ranked] ON [ranked].[id] = [target].[id]
SQL, $bindings);

        $negativeWhere = $definition['parent'] === null
            ? 'WHERE [Display_Order] < 0'
            : 'WHERE '.$this->sqlServerIdentifier($definition['parent']).' = ? AND [Display_Order] < 0';
        DB::update(
            "UPDATE {$table} SET [Display_Order] = -[Display_Order] * ".self::ORDER_STEP." {$negativeWhere}",
            $bindings,
        );
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function resetPortableScope(array $definition, ?int $parentId): int
    {
        $source = $definition['source'];
        $rows = $this->scopeQuery($definition, $parentId)
            ->select(['id', $source, 'Display_Order'])
            ->orderByRaw("CASE WHEN {$source} IS NULL THEN 1 ELSE 0 END")
            ->orderBy($source)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $changed = 0;
        foreach ($rows as $index => $row) {
            $expected = ($index + 1) * self::ORDER_STEP;
            if ((int) $row->Display_Order !== $expected) {
                $changed++;
            }
        }
        if ($changed === 0) {
            return 0;
        }

        foreach ($rows as $index => $row) {
            $updated = DB::table($definition['table'])
                ->where('id', $row->id)
                ->update(['Display_Order' => -($index + 1)]);
            if ($updated !== 1) {
                throw new RuntimeException('The category order could not be reset safely.');
            }
        }
        foreach ($rows as $index => $row) {
            $updated = DB::table($definition['table'])
                ->where('id', $row->id)
                ->update(['Display_Order' => ($index + 1) * self::ORDER_STEP]);
            if ($updated !== 1) {
                throw new RuntimeException('The category order could not be reset safely.');
            }
        }

        return $changed;
    }

    /** @param array{model: class-string<Model>, table: string, parent: ?string, source: string, code: string, name: string, name_ar: string, child_relation: ?string} $definition */
    private function resetSqlServerScope(array $definition, ?int $parentId, int $count): int
    {
        $table = $this->sqlServerIdentifier($definition['table']);
        $source = $this->sqlServerIdentifier($definition['source']);
        $where = $definition['parent'] === null
            ? ''
            : 'WHERE '.$this->sqlServerIdentifier($definition['parent']).' = ?';
        $bindings = $definition['parent'] === null ? [] : [$parentId];
        $step = self::ORDER_STEP;

        $difference = DB::selectOne(<<<SQL
WITH [ranked] AS (
    SELECT [id], ROW_NUMBER() OVER (
        ORDER BY CASE WHEN {$source} IS NULL THEN 1 ELSE 0 END, {$source}, [id]
    ) AS [row_number]
    FROM {$table} WITH (UPDLOCK, HOLDLOCK)
    {$where}
)
SELECT COUNT_BIG(*) AS [changed_count]
FROM {$table} AS [target]
INNER JOIN [ranked] ON [ranked].[id] = [target].[id]
WHERE [target].[Display_Order] <> CAST([ranked].[row_number] AS BIGINT) * {$step}
SQL, $bindings);
        $changed = (int) ($difference?->changed_count ?? 0);
        if ($changed === 0) {
            return 0;
        }

        DB::statement(<<<SQL
WITH [ranked] AS (
    SELECT [id], ROW_NUMBER() OVER (
        ORDER BY CASE WHEN {$source} IS NULL THEN 1 ELSE 0 END, {$source}, [id]
    ) AS [row_number]
    FROM {$table} WITH (UPDLOCK, HOLDLOCK)
    {$where}
)
UPDATE [target]
SET [Display_Order] = -CAST([ranked].[row_number] AS BIGINT)
FROM {$table} AS [target]
INNER JOIN [ranked] ON [ranked].[id] = [target].[id]
SQL, $bindings);

        $negativeWhere = $definition['parent'] === null
            ? 'WHERE [Display_Order] < 0'
            : 'WHERE '.$this->sqlServerIdentifier($definition['parent']).' = ? AND [Display_Order] < 0';
        $updated = DB::update(
            "UPDATE {$table} SET [Display_Order] = -[Display_Order] * {$step} {$negativeWhere}",
            $bindings,
        );
        if ($updated !== $count) {
            throw new RuntimeException('The category order could not be reset safely.');
        }

        return $changed;
    }

    public function acquireHierarchyLock(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        $result = DB::selectOne("DECLARE @result INT; EXEC @result = sp_getapplock @Resource = 'product-hierarchy-import', @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000; SELECT @result AS result;");
        if (! $result || (int) $result->result < 0) {
            throw new RuntimeException('Another hierarchy change is currently in progress. Please retry.', 409);
        }
    }

    /** @return array{item: array<string, mixed>, revision: int, moved: bool} */
    private function moveResult(string $level, Model $model, bool $moved, int $revision): array
    {
        $definition = $this->definition($level);
        if ($definition['child_relation'] !== null) {
            $model->loadCount([$definition['child_relation'].' as child_count']);
        } else {
            $model->setAttribute('child_count', 0);
        }
        if ($level === 'sub_department') {
            $model->loadMissing('productDepartment:id,Product_Department_Name');
        } elseif ($level === 'sub_sub_department') {
            $model->loadMissing('subDepartment:id,Products_Departments_Id,Sub_Department_Name');
            $model->loadMissing('subDepartment.productDepartment:id,Product_Department_Name');
        }

        return [
            'item' => $this->present($level, $model),
            'revision' => $revision,
            'moved' => $moved,
        ];
    }

    private function sqlServerIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) {
            throw new RuntimeException('Unsafe SQL Server hierarchy identifier.');
        }

        return '['.$identifier.']';
    }
}
