<?php

namespace App\Services;

use App\Models\ProductDepartments;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use App\Support\ProductHierarchyCode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ProductHierarchyManualAllocationService
{
    public function __construct(
        private readonly ProductHierarchyDisplayOrderService $displayOrder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createDepartment(array $attributes): ProductDepartments
    {
        return DB::transaction(function () use ($attributes): ProductDepartments {
            $this->acquireHierarchyLock();
            $this->displayOrder->lockRevisionState();

            $sequence = $this->nextSequence(
                (int) (ProductDepartments::query()->lockForUpdate()->max('Source_Main_Sequence') ?? 0),
                'department',
            );
            $period = now()->format('Y-m');
            $displayOrder = $this->displayOrder->nextAppendOrder('department', null);

            $department = ProductDepartments::create([
                ...$attributes,
                'Product_Department_Code' => ProductHierarchyCode::department($period, $sequence),
                'Source_Main_Id' => 'MAIN-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                'Source_Main_Sequence' => $sequence,
                'Hierarchy_Code_Period' => $period,
                'Display_Order' => $displayOrder,
            ]);
            $this->displayOrder->incrementRevision();

            return $department;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createSubDepartment(int $departmentId, array $attributes): ProductSubDepartment
    {
        return DB::transaction(function () use ($departmentId, $attributes): ProductSubDepartment {
            $this->acquireHierarchyLock();
            $this->displayOrder->lockRevisionState();

            $department = ProductDepartments::query()->lockForUpdate()->findOrFail($departmentId);
            $period = $this->validatedDepartmentPeriod($department);
            $sourceSequence = $this->nextSequence(
                (int) (ProductSubDepartment::query()
                    ->where('Products_Departments_Id', $department->id)
                    ->lockForUpdate()
                    ->max('Source_Sub_Sequence') ?? 0),
                'sub-department hierarchy',
            );
            $databaseSequence = $this->nextStoredCodeSequence(
                'Products_Sub_Department_T',
                'Products_Sub_Department_Code',
                $period,
                'sub_department',
            );
            $displayOrder = $this->displayOrder->nextAppendOrder('sub_department', (int) $department->id);

            $subDepartment = ProductSubDepartment::create([
                ...$attributes,
                'Products_Departments_Id' => $department->id,
                'Products_Sub_Department_Code' => ProductHierarchyCode::subDepartment($period, $databaseSequence),
                'Source_Sub_Sequence' => $sourceSequence,
                'Display_Order' => $displayOrder,
            ]);
            $this->displayOrder->incrementRevision();

            return $subDepartment;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createSubSubDepartment(int $subDepartmentId, array $attributes): ProductSubSubDepartment
    {
        return DB::transaction(function () use ($subDepartmentId, $attributes): ProductSubSubDepartment {
            $this->acquireHierarchyLock();
            $this->displayOrder->lockRevisionState();

            $subDepartment = ProductSubDepartment::query()->lockForUpdate()->findOrFail($subDepartmentId);
            $department = ProductDepartments::query()
                ->lockForUpdate()
                ->findOrFail((int) $subDepartment->Products_Departments_Id);
            $period = $this->validatedDepartmentPeriod($department);
            $sourceSequence = $this->nextSequence(
                (int) (ProductSubSubDepartment::query()
                    ->where('Product_Sub_Department_Id', $subDepartment->id)
                    ->lockForUpdate()
                    ->max('Source_Sub_Sub_Sequence') ?? 0),
                'sub-sub-department hierarchy',
            );
            $databaseSequence = $this->nextStoredCodeSequence(
                'Products_Sub_Sub_Department_T',
                'Product_Sub_Sub_Department_Code',
                $period,
                'sub_sub_department',
            );
            $displayOrder = $this->displayOrder->nextAppendOrder(
                'sub_sub_department',
                (int) $subDepartment->id,
            );

            $subSubDepartment = ProductSubSubDepartment::create([
                ...$attributes,
                'Product_Sub_Department_Id' => $subDepartment->id,
                'Product_Sub_Sub_Department_Code' => ProductHierarchyCode::subSubDepartment($period, $databaseSequence),
                'Source_Sub_Sub_Sequence' => $sourceSequence,
                'Display_Order' => $displayOrder,
            ]);
            $this->displayOrder->incrementRevision();

            return $subSubDepartment;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateSubSubDepartment(
        ProductSubSubDepartment $subSubDepartment,
        int $subDepartmentId,
        array $attributes,
    ): ProductSubSubDepartment {
        return DB::transaction(function () use (
            $subSubDepartment,
            $subDepartmentId,
            $attributes,
        ): ProductSubSubDepartment {
            $this->acquireHierarchyLock();
            $this->displayOrder->lockRevisionState();

            $locked = ProductSubSubDepartment::query()
                ->lockForUpdate()
                ->findOrFail($subSubDepartment->id);
            ProductSubDepartment::query()->lockForUpdate()->findOrFail($subDepartmentId);

            if ((int) $locked->Product_Sub_Department_Id !== $subDepartmentId) {
                $locked->Product_Sub_Department_Id = $subDepartmentId;
                $locked->Source_Sub_Sub_Sequence = $this->nextSequence(
                    (int) (ProductSubSubDepartment::query()
                        ->where('Product_Sub_Department_Id', $subDepartmentId)
                        ->lockForUpdate()
                        ->max('Source_Sub_Sub_Sequence') ?? 0),
                    'sub-sub-department hierarchy',
                );
                $locked->Display_Order = $this->displayOrder->nextAppendOrder(
                    'sub_sub_department',
                    $subDepartmentId,
                );
            }

            $locked->fill($attributes);
            $locked->save();
            $this->displayOrder->incrementRevision();

            return $locked->refresh();
        }, 3);
    }

    private function validatedDepartmentPeriod(ProductDepartments $department): string
    {
        try {
            $identity = ProductHierarchyCode::parseMainId((string) $department->Source_Main_Id);
            $period = ProductHierarchyCode::normalizePeriod((string) $department->Hierarchy_Code_Period);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                "Department {$department->id} has incomplete hierarchy metadata and cannot receive child categories.",
                422,
                $exception,
            );
        }

        if ((int) $department->Source_Main_Sequence !== $identity['sequence']) {
            throw new RuntimeException(
                "Department {$department->id} has conflicting hierarchy metadata and cannot receive child categories.",
                422,
            );
        }

        return $period;
    }

    private function nextStoredCodeSequence(
        string $table,
        string $column,
        string $period,
        string $type,
    ): int {
        $firstCode = $type === 'sub_department'
            ? ProductHierarchyCode::subDepartment($period, 1)
            : ProductHierarchyCode::subSubDepartment($period, 1);
        $prefix = substr($firstCode, 0, -6);
        $latest = DB::table($table)
            ->where($column, 'like', $prefix.'%')
            ->orderBy($column, 'desc')
            ->lockForUpdate()
            ->value($column);

        if ($latest === null) {
            return 1;
        }

        try {
            $parsed = $type === 'sub_department'
                ? ProductHierarchyCode::parseSubDepartment((string) $latest)
                : ProductHierarchyCode::parseSubSubDepartment((string) $latest);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('The latest hierarchy database code is invalid.', 409, $exception);
        }

        if ($parsed['period'] !== $period) {
            throw new RuntimeException('The hierarchy database-code period changed during allocation.', 409);
        }

        return $this->nextSequence($parsed['sequence'], $type.' database code');
    }

    private function nextSequence(int $maximum, string $label): int
    {
        if ($maximum < 0 || $maximum >= 999999) {
            throw new RuntimeException("No more {$label} sequences are available.", 422);
        }

        return $maximum + 1;
    }

    private function acquireHierarchyLock(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        $result = DB::selectOne("DECLARE @result INT; EXEC @result = sp_getapplock @Resource = 'product-hierarchy-import', @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000; SELECT @result AS result;");
        if (! $result || (int) $result->result < 0) {
            throw new RuntimeException('Another hierarchy change is currently in progress. Please retry.', 409);
        }
    }
}
