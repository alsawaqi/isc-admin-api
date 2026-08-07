<?php

namespace App\Services;

use App\Models\ProductDepartments;
use App\Models\ProductHierarchyImportJob;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use App\Support\HierarchyName;
use App\Support\ProductHierarchyCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ProductHierarchyImportService
{
    private const CREATED_ID_KEYS = ['departments', 'sub_departments', 'sub_sub_departments'];

    private ?ProductHierarchyDisplayOrderService $displayOrderService;

    public function __construct(?ProductHierarchyDisplayOrderService $displayOrderService = null)
    {
        $this->displayOrderService = $displayOrderService;
    }

    /** @return array<string, mixed> */
    public function analyze(array $parsed): array
    {
        try {
            $codePeriod = ProductHierarchyCode::normalizePeriod((string) ($parsed['code_period'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), 422, $exception);
        }

        $issues = array_values($parsed['issues'] ?? []);
        $counts = [
            'existing' => ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0],
            'create' => ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0],
            'link' => ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0],
        ];

        $departmentRows = ProductDepartments::query()->get([
            'id',
            'Product_Department_Code',
            'Product_Department_Name',
            'Source_Main_Id',
            'Source_Main_Sequence',
            'Hierarchy_Code_Period',
        ]);
        $departmentState = $this->departmentState($departmentRows);
        $departmentCodeOwners = $this->codeOwners($departmentRows, 'Product_Department_Code');
        $departmentModels = [];
        $plan = [];

        foreach ($parsed['hierarchy'] as $mainKey => $department) {
            $resolution = $this->resolveDepartment($department, $codePeriod, $departmentState);
            $canonicalCode = ProductHierarchyCode::department($codePeriod, (int) $department['main_sequence']);
            $status = $resolution['status'];
            $code = [
                'actual_code' => $resolution['model']?->Product_Department_Code,
                'canonical_code' => $canonicalCode,
                'code' => $resolution['model'] === null ? $canonicalCode : $resolution['model']->Product_Department_Code,
                'legacy_code' => false,
                'conflict' => false,
            ];

            if ($status === 'conflict') {
                $this->issue($issues, 'error', $department['first_row'], 'department_conflict', $resolution['message']);
            } else {
                $code = $this->evaluateCode(
                    $issues,
                    $departmentCodeOwners,
                    'department',
                    $department['name'],
                    $department['first_row'],
                    $canonicalCode,
                    $resolution['model']?->Product_Department_Code,
                    $resolution['model']?->id,
                    $status === 'create',
                    'department:'.$department['main_sequence'],
                );
                if ($code['conflict']) {
                    $status = 'conflict';
                }
            }

            if ($resolution['source_alias'] && $status !== 'conflict') {
                $this->issue(
                    $issues,
                    'warning',
                    $department['first_row'],
                    'existing_main_id_alias',
                    "Existing department {$department['name']} keeps Source_Main_Id {$resolution['model']->Source_Main_Id}; it represents the same numeric M-Id as {$department['main_id']}."
                );
            }

            $departmentPlan = [
                'main_id' => $department['main_id'],
                'main_sequence' => (int) $department['main_sequence'],
                'name' => $department['name'],
                'first_row' => $department['first_row'],
                'status' => $status,
                'database_id' => $resolution['model']?->id,
                'code_period' => $codePeriod,
                'link_fields' => $status === 'conflict' ? [] : $resolution['link_fields'],
                ...$code,
                'sub_departments' => [],
            ];
            unset($departmentPlan['conflict']);

            if ($status !== 'conflict') {
                $counts[$status]['departments']++;
                if ($departmentPlan['link_fields'] !== []) {
                    $counts['link']['departments']++;
                }
            }

            $plan[$mainKey] = $departmentPlan;
            $departmentModels[$mainKey] = $resolution['model'];
        }

        $subRows = ProductSubDepartment::query()->get([
            'id',
            'Products_Departments_Id',
            'Products_Sub_Department_Code',
            'Sub_Department_Name',
            'Source_Sub_Sequence',
        ]);
        $subState = $this->childState(
            $subRows,
            'Products_Departments_Id',
            'Sub_Department_Name',
            'Source_Sub_Sequence',
            'Sub Group',
        );
        $subCodeOwners = $this->codeOwners($subRows, 'Products_Sub_Department_Code');
        $nextSubDatabaseSequence = $this->maximumDatabaseCodeSequence(
            $subRows,
            'Products_Sub_Department_Code',
            'sub_department',
            $codePeriod,
        );
        $resolvedSubModels = [];

        foreach ($parsed['hierarchy'] as $mainKey => $department) {
            $departmentPlan = &$plan[$mainKey];
            $departmentModel = $departmentModels[$mainKey];
            $parentId = $departmentModel?->id;
            $nextSequence = $parentId ? ($subState['maximum'][$parentId] ?? 0) : 0;

            foreach ($department['sub_departments'] as $subKey => $sub) {
                $matches = $parentId
                    ? array_values($subState['by_name'][$parentId][HierarchyName::fingerprint($sub['name'])] ?? [])
                    : [];
                $status = $departmentPlan['status'] === 'conflict'
                    ? 'conflict'
                    : (count($matches) === 0 ? 'create' : (count($matches) === 1 ? 'existing' : 'conflict'));
                $model = $status === 'existing' ? $matches[0] : null;
                $sequence = null;
                $linkFields = [];

                if ($status === 'conflict' && $departmentPlan['status'] !== 'conflict') {
                    $this->issue(
                        $issues,
                        'error',
                        $sub['first_row'],
                        'sub_department_conflict',
                        "Multiple normalized matches exist for Sub Group {$sub['name']} under {$department['name']}."
                    );
                } elseif ($status !== 'conflict' && $model && isset($subState['metadata_errors'][$model->id])) {
                    $status = 'conflict';
                    $this->issue($issues, 'error', $sub['first_row'], 'sub_sequence_conflict', $subState['metadata_errors'][$model->id]);
                }

                if ($status !== 'conflict') {
                    if ($model && $model->Source_Sub_Sequence !== null) {
                        $sequence = (int) $model->Source_Sub_Sequence;
                    } else {
                        $sequence = $this->allocateSequence(
                            $nextSequence,
                            $issues,
                            $sub['first_row'],
                            'sub_sequence_exhausted',
                            "No more Sub Group sequences are available under {$department['name']}."
                        );
                        if ($sequence === null) {
                            $status = 'conflict';
                        } elseif ($model) {
                            $linkFields[] = 'source_sub_sequence';
                        }
                    }
                }

                $databaseSequence = null;
                if ($status !== 'conflict') {
                    $databaseSequence = $model
                        ? $this->databaseCodeSequence(
                            $model->Products_Sub_Department_Code,
                            'sub_department',
                            $departmentPlan['code_period'],
                        )
                        : null;
                    if ($databaseSequence === null) {
                        $databaseSequence = $this->allocateSequence(
                            $nextSubDatabaseSequence,
                            $issues,
                            $sub['first_row'],
                            'sub_database_sequence_exhausted',
                            'No more global Sub Group database-code sequences are available for '.$departmentPlan['code_period'].'.',
                        );
                        if ($databaseSequence === null) {
                            $status = 'conflict';
                        }
                    }
                }

                $canonicalCode = $databaseSequence === null
                    ? null
                    : ProductHierarchyCode::subDepartment(
                        $departmentPlan['code_period'],
                        $databaseSequence,
                    );
                $code = [
                    'actual_code' => $model?->Products_Sub_Department_Code,
                    'canonical_code' => $canonicalCode,
                    'code' => $model === null ? $canonicalCode : $model->Products_Sub_Department_Code,
                    'legacy_code' => false,
                    'conflict' => false,
                ];
                if ($status !== 'conflict' && $canonicalCode !== null) {
                    $code = $this->evaluateCode(
                        $issues,
                        $subCodeOwners,
                        'sub-department',
                        $sub['name'],
                        $sub['first_row'],
                        $canonicalCode,
                        $model?->Products_Sub_Department_Code,
                        $model?->id,
                        $status === 'create',
                        'sub:'.$departmentPlan['code_period'].':'.$databaseSequence,
                    );
                    if ($code['conflict']) {
                        $status = 'conflict';
                        $linkFields = [];
                    }
                }

                $subPlan = [
                    'name' => $sub['name'],
                    'first_row' => $sub['first_row'],
                    'status' => $status,
                    'database_id' => $model?->id,
                    'sub_sequence' => $sequence,
                    'database_code_sequence' => $databaseSequence,
                    'link_fields' => $status === 'conflict' ? [] : $linkFields,
                    ...$code,
                    'sub_sub_departments' => [],
                ];
                unset($subPlan['conflict']);

                if ($status !== 'conflict') {
                    $counts[$status]['sub_departments']++;
                    if ($subPlan['link_fields'] !== []) {
                        $counts['link']['sub_departments']++;
                    }
                }

                $departmentPlan['sub_departments'][$subKey] = $subPlan;
                $resolvedSubModels[$mainKey.'|'.$subKey] = $model;
            }
            unset($departmentPlan);
        }

        $leafRows = ProductSubSubDepartment::query()->get([
            'id',
            'Product_Sub_Department_Id',
            'Product_Sub_Sub_Department_Code',
            'Product_Sub_Sub_Department_Name',
            'Source_Sub_Sub_Sequence',
            'Slug',
        ]);
        $leafState = $this->childState(
            $leafRows,
            'Product_Sub_Department_Id',
            'Product_Sub_Sub_Department_Name',
            'Source_Sub_Sub_Sequence',
            'Sub Sub Category',
        );
        $leafCodeOwners = $this->codeOwners($leafRows, 'Product_Sub_Sub_Department_Code');
        $nextLeafDatabaseSequence = $this->maximumDatabaseCodeSequence(
            $leafRows,
            'Product_Sub_Sub_Department_Code',
            'sub_sub_department',
            $codePeriod,
        );
        $slugOwners = [];
        foreach ($leafRows as $leafRow) {
            if ($leafRow->Slug) {
                $slugOwners[HierarchyName::key($leafRow->Slug)] = true;
            }
        }

        foreach ($parsed['hierarchy'] as $mainKey => $department) {
            foreach ($department['sub_departments'] as $subKey => $sub) {
                $subPlan = &$plan[$mainKey]['sub_departments'][$subKey];
                $subModel = $resolvedSubModels[$mainKey.'|'.$subKey];
                $parentId = $subModel?->id;
                $nextSequence = $parentId ? ($leafState['maximum'][$parentId] ?? 0) : 0;

                foreach ($sub['sub_sub_departments'] as $leafKey => $leaf) {
                    $matches = $parentId
                        ? array_values($leafState['by_name'][$parentId][HierarchyName::fingerprint($leaf['name'])] ?? [])
                        : [];
                    $status = $subPlan['status'] === 'conflict'
                        ? 'conflict'
                        : (count($matches) === 0 ? 'create' : (count($matches) === 1 ? 'existing' : 'conflict'));
                    $model = $status === 'existing' ? $matches[0] : null;
                    $sequence = null;
                    $linkFields = [];

                    if ($status === 'conflict' && $subPlan['status'] !== 'conflict') {
                        $this->issue(
                            $issues,
                            'error',
                            $leaf['first_row'],
                            'sub_sub_department_conflict',
                            "Multiple normalized matches exist for Sub Sub Category {$leaf['name']}."
                        );
                    } elseif ($status !== 'conflict' && $model && isset($leafState['metadata_errors'][$model->id])) {
                        $status = 'conflict';
                        $this->issue($issues, 'error', $leaf['first_row'], 'sub_sub_sequence_conflict', $leafState['metadata_errors'][$model->id]);
                    }

                    if ($status !== 'conflict') {
                        if ($model && $model->Source_Sub_Sub_Sequence !== null) {
                            $sequence = (int) $model->Source_Sub_Sub_Sequence;
                        } else {
                            $sequence = $this->allocateSequence(
                                $nextSequence,
                                $issues,
                                $leaf['first_row'],
                                'sub_sub_sequence_exhausted',
                                "No more Sub Sub Category sequences are available under {$sub['name']}."
                            );
                            if ($sequence === null) {
                                $status = 'conflict';
                            } elseif ($model) {
                                $linkFields[] = 'source_sub_sub_sequence';
                            }
                        }
                    }

                    $databaseSequence = null;
                    if ($status !== 'conflict') {
                        $databaseSequence = $model
                            ? $this->databaseCodeSequence(
                                $model->Product_Sub_Sub_Department_Code,
                                'sub_sub_department',
                                $plan[$mainKey]['code_period'],
                            )
                            : null;
                        if ($databaseSequence === null) {
                            $databaseSequence = $this->allocateSequence(
                                $nextLeafDatabaseSequence,
                                $issues,
                                $leaf['first_row'],
                                'sub_sub_database_sequence_exhausted',
                                'No more global Sub Sub Category database-code sequences are available for '.$plan[$mainKey]['code_period'].'.',
                            );
                            if ($databaseSequence === null) {
                                $status = 'conflict';
                            }
                        }
                    }

                    $canonicalCode = $databaseSequence === null
                        ? null
                        : ProductHierarchyCode::subSubDepartment(
                            $plan[$mainKey]['code_period'],
                            $databaseSequence,
                        );
                    $code = [
                        'actual_code' => $model?->Product_Sub_Sub_Department_Code,
                        'canonical_code' => $canonicalCode,
                        'code' => $model === null ? $canonicalCode : $model->Product_Sub_Sub_Department_Code,
                        'legacy_code' => false,
                        'conflict' => false,
                    ];
                    if ($status !== 'conflict' && $canonicalCode !== null) {
                        $code = $this->evaluateCode(
                            $issues,
                            $leafCodeOwners,
                            'sub-sub-department',
                            $leaf['name'],
                            $leaf['first_row'],
                            $canonicalCode,
                            $model?->Product_Sub_Sub_Department_Code,
                            $model?->id,
                            $status === 'create',
                            'leaf:'.$plan[$mainKey]['code_period'].':'.$databaseSequence,
                        );
                        if ($code['conflict']) {
                            $status = 'conflict';
                            $linkFields = [];
                        }
                    }

                    $slug = $model?->Slug;
                    if ($status === 'create') {
                        $identity = $department['main_id'].'|'.$sub['name'].'|'.$leaf['name'];
                        $slug = $this->slug($department['name'], $sub['name'], $leaf['name'], $identity);
                        $slugKey = HierarchyName::key($slug);
                        if (isset($slugOwners[$slugKey])) {
                            $this->issue(
                                $issues,
                                'error',
                                $leaf['first_row'],
                                'generated_slug_collision',
                                "Generated slug {$slug} is already assigned to another Sub Sub Category."
                            );
                            $status = 'conflict';
                            $linkFields = [];
                        } else {
                            $slugOwners[$slugKey] = true;
                        }
                    }

                    $leafPlan = [
                        'name' => $leaf['name'],
                        'first_row' => $leaf['first_row'],
                        'status' => $status,
                        'database_id' => $model?->id,
                        'sub_sub_sequence' => $sequence,
                        'database_code_sequence' => $databaseSequence,
                        'link_fields' => $status === 'conflict' ? [] : $linkFields,
                        'slug' => $slug,
                        ...$code,
                    ];
                    unset($leafPlan['conflict']);

                    if ($status !== 'conflict') {
                        $counts[$status]['sub_sub_departments']++;
                        if ($leafPlan['link_fields'] !== []) {
                            $counts['link']['sub_sub_departments']++;
                        }
                    }

                    $subPlan['sub_sub_departments'][$leafKey] = $leafPlan;
                }
                unset($subPlan);
            }
        }

        $issues = array_values($issues);
        $errorCount = count(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'error'));
        $warningCount = count(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'warning'));
        $summary = [
            'code_period' => $codePeriod,
            'rows_read' => $parsed['rows_read'],
            'separator_rows' => $parsed['separator_rows'],
            'blank_leaf_rows' => $parsed['blank_leaf_rows'],
            'valid_paths' => $parsed['valid_paths'],
            'departments' => $parsed['departments'],
            'sub_departments' => $parsed['sub_departments'],
            'sub_sub_departments' => $parsed['sub_sub_departments'],
            'duplicate_paths' => $parsed['duplicate_paths'],
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'existing' => $counts['existing'],
            'create' => $counts['create'],
            'link' => $counts['link'],
        ];

        return [
            'code_period' => $codePeriod,
            'summary' => $summary,
            'can_commit' => $errorCount === 0,
            'issues' => $issues,
            'hierarchy' => $this->publicHierarchy($plan),
            '_plan' => $plan,
        ];
    }

    public function planDigest(array $analysis): string
    {
        if (! isset($analysis['_plan']) || ! is_array($analysis['_plan'])) {
            throw new RuntimeException('The hierarchy allocation plan is missing.', 500);
        }

        return hash(
            'sha256',
            json_encode($analysis['_plan'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string, mixed> */
    public function commit(string $token, int $userId): array
    {
        $result = DB::transaction(function () use ($token, $userId) {
            $job = ProductHierarchyImportJob::query()->where('Token', $token)->lockForUpdate()->first();
            if (! $job) {
                throw new RuntimeException('The preview token is invalid or expired.', 422);
            }
            if ((int) $job->User_Id !== $userId) {
                throw new RuntimeException('This preview belongs to another administrator.', 403);
            }
            if ($job->Status === 'committed') {
                return json_decode($job->Result ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            }
            if ($job->Status !== 'pending') {
                throw new RuntimeException('This preview is no longer available.', 409);
            }
            if ($job->Expires_At->isPast()) {
                $job->update(['Status' => 'expired']);

                return new RuntimeException('The preview token has expired. Upload the workbook again.', 422);
            }
            if (! $job->Can_Commit) {
                throw new RuntimeException('The workbook has blocking validation errors and cannot be committed.', 422);
            }
            if (! hash_equals($job->Payload_Digest, hash('sha256', $job->Canonical_Payload))) {
                throw new RuntimeException('The saved import preview failed its integrity check.', 409);
            }

            $parsed = json_decode($job->Canonical_Payload, true, 512, JSON_THROW_ON_ERROR);
            $expectedPlanDigest = (string) ($parsed['_allocation_digest'] ?? '');
            if (! preg_match('/^[a-f0-9]{64}$/D', $expectedPlanDigest)) {
                throw new RuntimeException('The saved import preview is missing its allocation proof.', 409);
            }

            $this->acquireImportLock();
            $analysis = $this->analyze($parsed);
            if (! $analysis['can_commit']) {
                throw new RuntimeException('The category database changed after preview. Upload and review the workbook again.', 409);
            }
            if (! hash_equals($expectedPlanDigest, $this->planDigest($analysis))) {
                throw new RuntimeException('Hierarchy code allocations changed after preview. Upload and review the workbook again.', 409);
            }

            $result = $this->applyPlan($analysis['_plan'], $userId, $parsed['code_period']);
            $job->update([
                'Status' => 'committed',
                'Committed_At' => now(),
                'Result' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);

            return $result;
        }, 3);

        if ($result instanceof RuntimeException) {
            throw $result;
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function history(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));

        return ProductHierarchyImportJob::query()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ProductHierarchyImportJob $job): array => $this->publicJob($job))
            ->all();
    }

    /** @return array<string, mixed> */
    public function rollback(int $jobId, int $userId): array
    {
        $result = DB::transaction(function () use ($jobId, $userId) {
            $job = ProductHierarchyImportJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if (! $job) {
                throw new RuntimeException('The import batch was not found.', 404);
            }

            $result = $this->decodeJsonObject($job->Result);
            if ($job->Status === 'rolled_back') {
                return $result['rollback'] ?? [
                    'job' => $this->publicJob($job),
                    'already_rolled_back' => true,
                ];
            }
            if ($job->Status !== 'committed') {
                throw new RuntimeException('Only committed hierarchy imports can be rolled back.', 409);
            }
            if (! $this->hasRollbackTracking($result)) {
                throw new RuntimeException('This import was committed before rollback tracking was available.', 422);
            }

            $this->acquireImportLock();
            $displayOrder = $this->displayOrder();
            $displayOrder->lockRevisionState();

            $createdIds = $this->normaliseTrackedIds($result['created_ids'] ?? []);
            $linkedRecords = $this->normaliseLinkedRecords($result['linked_records'] ?? []);

            $this->assertRollbackHasNoBusinessReferences($createdIds);
            $deleted = [
                'sub_sub_departments' => $this->deleteTrackedRows(
                    'Products_Sub_Sub_Department_T',
                    $createdIds['sub_sub_departments'],
                    'sub-sub-department',
                ),
                'sub_departments' => $this->deleteTrackedRows(
                    'Products_Sub_Department_T',
                    $createdIds['sub_departments'],
                    'sub-department',
                ),
                'departments' => $this->deleteTrackedRows(
                    'Products_Departments_T',
                    $createdIds['departments'],
                    'department',
                ),
            ];
            $metadataCleared = $this->rollbackLinkedMetadata($linkedRecords);
            if (array_sum($deleted) > 0) {
                $displayOrder->incrementRevision();
            }

            $rollback = [
                'job_id' => (int) $job->id,
                'rolled_back_by' => $userId,
                'rolled_back_at' => now()->toIso8601String(),
                'deleted' => $deleted,
                'metadata_cleared' => $metadataCleared,
            ];
            $result['rollback'] = $rollback;

            $job->update([
                'Status' => 'rolled_back',
                'Rolled_Back_At' => now(),
                'Rolled_Back_By' => $userId,
                'Result' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);

            return [
                ...$rollback,
                'job' => $this->publicJob($job->refresh()),
            ];
        }, 3);

        return $result;
    }

    /** @param array<string, array<string, mixed>> $plan */
    private function applyPlan(array $plan, int $userId, string $codePeriod): array
    {
        $created = ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0];
        $skipped = ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0];
        $linked = ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0];
        $createdIds = ['departments' => [], 'sub_departments' => [], 'sub_sub_departments' => []];
        $linkedRecords = ['departments' => [], 'sub_departments' => [], 'sub_sub_departments' => []];
        $displayOrder = $this->displayOrder();
        $displayOrder->lockRevisionState();
        $nextDisplayOrders = [];
        $displayOrderChanged = false;
        $allocateDisplayOrder = function (string $level, ?int $parentId) use (
            $displayOrder,
            &$nextDisplayOrders,
        ): int {
            $key = $level.':'.($parentId ?? 'root');
            if (array_key_exists($key, $nextDisplayOrders)) {
                $nextDisplayOrders[$key] = ProductHierarchyDisplayOrderService::appendRank(
                    $nextDisplayOrders[$key],
                );

                return $nextDisplayOrders[$key];
            }

            $nextDisplayOrders[$key] = $displayOrder->nextAppendOrder($level, $parentId);

            return $nextDisplayOrders[$key];
        };

        foreach ($plan as $department) {
            if ($department['status'] === 'conflict') {
                throw new RuntimeException('The hierarchy allocation plan contains a department conflict.', 409);
            }

            if ($department['database_id']) {
                $departmentModel = ProductDepartments::query()->lockForUpdate()->find($department['database_id']);
                if (! $departmentModel) {
                    throw new RuntimeException('An existing department disappeared after preview.', 409);
                }
                $this->assertDepartmentMatchesPlan($departmentModel, $department);
                $skipped['departments']++;
                if ($department['link_fields'] !== []) {
                    $this->linkDepartmentMetadata($departmentModel, $department);
                    $linked['departments']++;
                    $linkedRecords['departments'][] = [
                        'id' => (int) $departmentModel->id,
                        'fields' => $this->departmentLinkedFields($department),
                    ];
                }
            } else {
                $departmentModel = ProductDepartments::create([
                    'Product_Department_Code' => $department['canonical_code'],
                    'Source_Main_Id' => $department['main_id'],
                    'Source_Main_Sequence' => $department['main_sequence'],
                    'Hierarchy_Code_Period' => $department['code_period'],
                    'Product_Department_Name' => $department['name'],
                    'Product_Department_Name_Ar' => null,
                    'Created_Date' => now(),
                    'Created_By' => $userId,
                    'Display_Order' => $allocateDisplayOrder('department', null),
                ]);
                $created['departments']++;
                $createdIds['departments'][] = (int) $departmentModel->id;
                $displayOrderChanged = true;
            }

            foreach ($department['sub_departments'] as $sub) {
                if ($sub['status'] === 'conflict') {
                    throw new RuntimeException('The hierarchy allocation plan contains a sub-department conflict.', 409);
                }

                if ($sub['database_id']) {
                    $subModel = ProductSubDepartment::query()->lockForUpdate()->find($sub['database_id']);
                    if (! $subModel) {
                        throw new RuntimeException('An existing sub-department changed after preview.', 409);
                    }
                    $this->assertChildMatchesPlan(
                        $subModel,
                        $sub,
                        'Products_Departments_Id',
                        (int) $departmentModel->id,
                        'Sub_Department_Name',
                        'Products_Sub_Department_Code',
                        'Source_Sub_Sequence',
                        'sub_sequence',
                    );
                    $skipped['sub_departments']++;
                    if ($sub['link_fields'] !== []) {
                        $this->linkSequenceMetadata($subModel, 'Source_Sub_Sequence', $sub['sub_sequence']);
                        $linked['sub_departments']++;
                        $linkedRecords['sub_departments'][] = [
                            'id' => (int) $subModel->id,
                            'fields' => ['Source_Sub_Sequence' => (int) $sub['sub_sequence']],
                        ];
                    }
                } else {
                    $subModel = ProductSubDepartment::create([
                        'Products_Departments_Id' => $departmentModel->id,
                        'Products_Sub_Department_Code' => $sub['canonical_code'],
                        'Source_Sub_Sequence' => $sub['sub_sequence'],
                        'Sub_Department_Name' => $sub['name'],
                        'Sub_Department_Name_Ar' => null,
                        'Created_Date' => now(),
                        'Created_By' => $userId,
                        'Display_Order' => $allocateDisplayOrder(
                            'sub_department',
                            (int) $departmentModel->id,
                        ),
                    ]);
                    $created['sub_departments']++;
                    $createdIds['sub_departments'][] = (int) $subModel->id;
                    $displayOrderChanged = true;
                }

                foreach ($sub['sub_sub_departments'] as $leaf) {
                    if ($leaf['status'] === 'conflict') {
                        throw new RuntimeException('The hierarchy allocation plan contains a sub-sub-department conflict.', 409);
                    }

                    if ($leaf['database_id']) {
                        $leafModel = ProductSubSubDepartment::query()->lockForUpdate()->find($leaf['database_id']);
                        if (! $leafModel) {
                            throw new RuntimeException('An existing sub-sub-department changed after preview.', 409);
                        }
                        $this->assertChildMatchesPlan(
                            $leafModel,
                            $leaf,
                            'Product_Sub_Department_Id',
                            (int) $subModel->id,
                            'Product_Sub_Sub_Department_Name',
                            'Product_Sub_Sub_Department_Code',
                            'Source_Sub_Sub_Sequence',
                            'sub_sub_sequence',
                            'Slug',
                        );
                        $skipped['sub_sub_departments']++;
                        if ($leaf['link_fields'] !== []) {
                            $this->linkSequenceMetadata($leafModel, 'Source_Sub_Sub_Sequence', $leaf['sub_sub_sequence']);
                            $linked['sub_sub_departments']++;
                            $linkedRecords['sub_sub_departments'][] = [
                                'id' => (int) $leafModel->id,
                                'fields' => ['Source_Sub_Sub_Sequence' => (int) $leaf['sub_sub_sequence']],
                            ];
                        }
                    } else {
                        $leafModel = ProductSubSubDepartment::create([
                            'Product_Sub_Department_Id' => $subModel->id,
                            'Product_Sub_Sub_Department_Code' => $leaf['canonical_code'],
                            'Source_Sub_Sub_Sequence' => $leaf['sub_sub_sequence'],
                            'Product_Sub_Sub_Department_Name' => $leaf['name'],
                            'Product_Sub_Sub_Department_Name_Ar' => null,
                            'Slug' => $leaf['slug'],
                            'Created_Date' => now(),
                            'Created_By' => $userId,
                            'Display_Order' => $allocateDisplayOrder(
                                'sub_sub_department',
                                (int) $subModel->id,
                            ),
                        ]);
                        $created['sub_sub_departments']++;
                        $createdIds['sub_sub_departments'][] = (int) $leafModel->id;
                        $displayOrderChanged = true;
                    }
                }
            }
        }

        if ($displayOrderChanged) {
            $displayOrder->incrementRevision();
        }

        return [
            'code_period' => ProductHierarchyCode::normalizePeriod($codePeriod),
            'created' => $created,
            'skipped' => $skipped,
            'linked' => $linked,
            'created_ids' => $createdIds,
            'linked_records' => $linkedRecords,
            'errors' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function publicJob(ProductHierarchyImportJob $job): array
    {
        $summary = $this->decodeJsonObject($job->Summary);
        $result = $this->decodeJsonObject($job->Result);

        return [
            'id' => (int) $job->id,
            'file_name' => $job->File_Name,
            'file_size' => (int) $job->File_Size,
            'file_sha256' => $job->File_Sha256,
            'status' => $job->Status,
            'can_commit' => (bool) $job->Can_Commit,
            'can_rollback' => $job->Status === 'committed' && $this->hasRollbackTracking($result),
            'code_period' => $result['code_period'] ?? $summary['code_period'] ?? null,
            'summary' => $summary,
            'result' => [
                'created' => $result['created'] ?? null,
                'skipped' => $result['skipped'] ?? null,
                'linked' => $result['linked'] ?? null,
                'rollback' => $result['rollback'] ?? null,
            ],
            'expires_at' => $job->Expires_At?->toIso8601String(),
            'committed_at' => $job->Committed_At?->toIso8601String(),
            'rolled_back_at' => $job->Rolled_Back_At?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function displayOrder(): ProductHierarchyDisplayOrderService
    {
        return $this->displayOrderService ??= app(ProductHierarchyDisplayOrderService::class);
    }

    private function hasRollbackTracking(array $result): bool
    {
        return array_key_exists('created_ids', $result)
            && array_key_exists('linked_records', $result);
    }

    /** @return array<string, array<int, int>> */
    private function normaliseTrackedIds(array $createdIds): array
    {
        $normalised = [];
        foreach (self::CREATED_ID_KEYS as $key) {
            $normalised[$key] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                is_array($createdIds[$key] ?? null) ? $createdIds[$key] : [],
            ), static fn (int $id): bool => $id > 0)));
        }

        return $normalised;
    }

    /** @return array<string, array<int, array{id: int, fields: array<string, mixed>}>> */
    private function normaliseLinkedRecords(array $records): array
    {
        $normalised = [];
        foreach (self::CREATED_ID_KEYS as $key) {
            $normalised[$key] = [];
            foreach (is_array($records[$key] ?? null) ? $records[$key] : [] as $record) {
                $id = (int) ($record['id'] ?? 0);
                $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];
                if ($id > 0 && $fields !== []) {
                    $normalised[$key][] = compact('id', 'fields');
                }
            }
        }

        return $normalised;
    }

    /** @param array<string, array<int, int>> $createdIds */
    private function assertRollbackHasNoBusinessReferences(array $createdIds): void
    {
        $this->assertNoUnexpectedChildren(
            'Products_Sub_Department_T',
            'Products_Departments_Id',
            $createdIds['departments'],
            $createdIds['sub_departments'],
            'sub-departments added after this import',
        );
        $this->assertNoUnexpectedChildren(
            'Products_Sub_Sub_Department_T',
            'Product_Sub_Department_Id',
            $createdIds['sub_departments'],
            $createdIds['sub_sub_departments'],
            'sub-sub-departments added after this import',
        );

        foreach ([
            ['Products_Master_T', 'Product_Department_Id', $createdIds['departments'], 'products linked to imported departments'],
            ['Products_Master_T', 'Product_Sub_Department_Id', $createdIds['sub_departments'], 'products linked to imported sub-departments'],
            ['Products_Master_T', 'Product_Sub_Sub_Department_Id', $createdIds['sub_sub_departments'], 'products linked to imported sub-sub-departments'],
            ['Products_Temporary_T', 'Product_Department_Id', $createdIds['departments'], 'temporary vendor products linked to imported departments'],
            ['Products_Temporary_T', 'Product_Sub_Department_Id', $createdIds['sub_departments'], 'temporary vendor products linked to imported sub-departments'],
            ['Products_Temporary_T', 'Product_Sub_Sub_Department_Id', $createdIds['sub_sub_departments'], 'temporary vendor products linked to imported sub-sub-departments'],
            ['Products_Discounts_T', 'Product_Department_Id', $createdIds['departments'], 'discounts linked to imported departments'],
            ['Products_Discounts_T', 'Product_Sub_Department_Id', $createdIds['sub_departments'], 'discounts linked to imported sub-departments'],
            ['Products_Discounts_T', 'Product_Sub_Sub_Department_Id', $createdIds['sub_sub_departments'], 'discounts linked to imported sub-sub-departments'],
            ['Products_Manufacture_Master_T', 'Product_Department_Id', $createdIds['departments'], 'manufacturers linked to imported departments'],
        ] as [$table, $column, $ids, $message]) {
            $this->assertNoReferences($table, $column, $ids, $message);
        }
    }

    /** @param array<int, int> $parentIds @param array<int, int> $expectedChildIds */
    private function assertNoUnexpectedChildren(
        string $table,
        string $parentColumn,
        array $parentIds,
        array $expectedChildIds,
        string $message
    ): void {
        if ($parentIds === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $parentColumn)) {
            return;
        }

        $query = DB::table($table)->whereIn($parentColumn, $parentIds);
        if ($expectedChildIds !== []) {
            $query->whereNotIn('id', $expectedChildIds);
        }

        if ($query->exists()) {
            throw new RuntimeException('Cannot roll back this import because it has '.$message.'.', 409);
        }
    }

    /** @param array<int, int> $ids */
    private function assertNoReferences(string $table, string $column, array $ids, string $message): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (DB::table($table)->whereIn($column, $ids)->exists()) {
            throw new RuntimeException('Cannot roll back this import because it has '.$message.'.', 409);
        }
    }

    /** @param array<int, int> $ids */
    private function deleteTrackedRows(string $table, array $ids, string $label): int
    {
        if ($ids === []) {
            return 0;
        }

        $existing = DB::table($table)->whereIn('id', $ids)->count();
        if ((int) $existing !== count($ids)) {
            throw new RuntimeException('Cannot roll back this import because an imported '.$label.' was changed or removed manually.', 409);
        }

        return DB::table($table)->whereIn('id', $ids)->delete();
    }

    /**
     * @param  array<string, array<int, array{id: int, fields: array<string, mixed>}>>  $records
     * @return array<string, int>
     */
    private function rollbackLinkedMetadata(array $records): array
    {
        $cleared = ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0];
        foreach ([
            'departments' => 'Products_Departments_T',
            'sub_departments' => 'Products_Sub_Department_T',
            'sub_sub_departments' => 'Products_Sub_Sub_Department_T',
        ] as $key => $table) {
            foreach ($records[$key] as $record) {
                $row = DB::table($table)->where('id', $record['id'])->lockForUpdate()->first();
                if (! $row) {
                    throw new RuntimeException('Cannot roll back this import because a linked category was removed manually.', 409);
                }

                foreach ($record['fields'] as $column => $expected) {
                    if (! property_exists($row, $column) || ! $this->sameScalar($row->{$column}, $expected)) {
                        throw new RuntimeException('Cannot roll back this import because linked category metadata changed after import.', 409);
                    }
                }

                DB::table($table)->where('id', $record['id'])->update(array_fill_keys(array_keys($record['fields']), null));
                $cleared[$key]++;
            }
        }

        return $cleared;
    }

    private function sameScalar(mixed $actual, mixed $expected): bool
    {
        if (is_int($expected)) {
            return (int) $actual === $expected;
        }

        return $this->nullableString($actual) === $this->nullableString($expected);
    }

    /** @return array<string, mixed> */
    private function departmentLinkedFields(array $plan): array
    {
        $fields = [];
        foreach ($plan['link_fields'] as $field) {
            match ($field) {
                'source_main_id' => $fields['Source_Main_Id'] = $plan['main_id'],
                'source_main_sequence' => $fields['Source_Main_Sequence'] = (int) $plan['main_sequence'],
                'hierarchy_code_period' => $fields['Hierarchy_Code_Period'] = $plan['code_period'],
                default => throw new RuntimeException('The hierarchy plan contains an unknown department metadata field.', 409),
            };
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function departmentState(Collection $rows): array
    {
        $bySequence = [];
        $byName = [];
        $metadataErrors = [];

        foreach ($rows as $row) {
            $byName[HierarchyName::key($row->Product_Department_Name)][$row->id] = $row;
            $sequences = [];

            if ($row->Source_Main_Sequence !== null) {
                try {
                    $sequences[] = $this->storedSequence($row->Source_Main_Sequence, 'Source_Main_Sequence');
                } catch (InvalidArgumentException $exception) {
                    $metadataErrors[$row->id] = "Department {$row->Product_Department_Name} has invalid Source_Main_Sequence metadata: {$exception->getMessage()}";
                }
            }

            if ($row->Source_Main_Id !== null && trim((string) $row->Source_Main_Id) !== '') {
                try {
                    $sequences[] = ProductHierarchyCode::parseMainId((string) $row->Source_Main_Id)['sequence'];
                } catch (InvalidArgumentException $exception) {
                    $metadataErrors[$row->id] = "Department {$row->Product_Department_Name} has invalid Source_Main_Id metadata: {$exception->getMessage()}";
                }
            }

            $sequences = array_values(array_unique($sequences));
            if (count($sequences) > 1) {
                $metadataErrors[$row->id] = "Department {$row->Product_Department_Name} has conflicting Source_Main_Id and Source_Main_Sequence metadata.";
            }
            foreach ($sequences as $sequence) {
                $bySequence[$sequence][$row->id] = $row;
            }
        }

        return compact('bySequence', 'byName', 'metadataErrors');
    }

    /** @return array<string, mixed> */
    private function resolveDepartment(array $department, string $period, array $state): array
    {
        $sequence = (int) $department['main_sequence'];
        $sequenceMatches = array_values($state['bySequence'][$sequence] ?? []);
        if (count($sequenceMatches) > 1) {
            return $this->departmentConflict("M-Id {$department['main_id']} resolves to more than one existing department.");
        }

        $model = $sequenceMatches[0] ?? null;
        if ($model && HierarchyName::key($model->Product_Department_Name) !== HierarchyName::key($department['name'])) {
            return $this->departmentConflict("M-Id {$department['main_id']} is already linked to a different department name.");
        }

        if (! $model) {
            $nameMatches = array_values($state['byName'][HierarchyName::key($department['name'])] ?? []);
            if (count($nameMatches) > 1) {
                return $this->departmentConflict("Department {$department['name']} has multiple existing normalized-name matches.");
            }
            $model = $nameMatches[0] ?? null;
        }

        if (! $model) {
            return [
                'status' => 'create',
                'model' => null,
                'link_fields' => [],
                'source_alias' => false,
                'message' => null,
            ];
        }

        if (isset($state['metadataErrors'][$model->id])) {
            return $this->departmentConflict($state['metadataErrors'][$model->id]);
        }

        $linkFields = [];
        $sourceAlias = false;
        if ($model->Source_Main_Id === null || trim((string) $model->Source_Main_Id) === '') {
            $linkFields[] = 'source_main_id';
        } else {
            $storedMain = ProductHierarchyCode::parseMainId((string) $model->Source_Main_Id);
            if ($storedMain['sequence'] !== $sequence) {
                return $this->departmentConflict("Department {$department['name']} is linked to another M-Id.");
            }
            $sourceAlias = $storedMain['source_id'] !== $department['main_id'];
        }

        if ($model->Source_Main_Sequence === null) {
            $linkFields[] = 'source_main_sequence';
        } elseif ((int) $model->Source_Main_Sequence !== $sequence) {
            return $this->departmentConflict("Department {$department['name']} has a different stored main sequence.");
        }

        if ($model->Hierarchy_Code_Period === null || trim((string) $model->Hierarchy_Code_Period) === '') {
            $linkFields[] = 'hierarchy_code_period';
        } else {
            try {
                $storedPeriod = ProductHierarchyCode::normalizePeriod((string) $model->Hierarchy_Code_Period);
            } catch (InvalidArgumentException $exception) {
                return $this->departmentConflict("Department {$department['name']} has an invalid stored hierarchy code period.");
            }
            if ($storedPeriod !== $period) {
                return $this->departmentConflict("Department {$department['name']} is assigned to code period {$storedPeriod}, not {$period}.");
            }
        }

        return [
            'status' => 'existing',
            'model' => $model,
            'link_fields' => $linkFields,
            'source_alias' => $sourceAlias,
            'message' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function departmentConflict(string $message): array
    {
        return [
            'status' => 'conflict',
            'model' => null,
            'link_fields' => [],
            'source_alias' => false,
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function childState(
        Collection $rows,
        string $parentColumn,
        string $nameColumn,
        string $sequenceColumn,
        string $label,
    ): array {
        $byName = [];
        $maximum = [];
        $sequenceOwners = [];
        $metadataErrors = [];

        foreach ($rows as $row) {
            $parentId = (int) $row->{$parentColumn};
            $byName[$parentId][HierarchyName::fingerprint($row->{$nameColumn})][$row->id] = $row;
            if ($row->{$sequenceColumn} === null) {
                continue;
            }

            try {
                $sequence = $this->storedSequence($row->{$sequenceColumn}, $sequenceColumn);
            } catch (InvalidArgumentException $exception) {
                $metadataErrors[$row->id] = "{$label} {$row->{$nameColumn}} has invalid sequence metadata: {$exception->getMessage()}";

                continue;
            }

            $sequenceOwners[$parentId][$sequence][$row->id] = true;
            $maximum[$parentId] = max($maximum[$parentId] ?? 0, $sequence);
        }

        foreach ($sequenceOwners as $parentId => $sequences) {
            foreach ($sequences as $sequence => $owners) {
                if (count($owners) < 2) {
                    continue;
                }
                foreach (array_keys($owners) as $id) {
                    $metadataErrors[$id] = "Multiple {$label} records under the same parent use sequence {$sequence}.";
                }
            }
        }

        return compact('byName', 'maximum', 'metadataErrors');
    }

    /** @return array<string, array<string, bool>> */
    private function codeOwners(Collection $rows, string $column): array
    {
        $owners = [];
        foreach ($rows as $row) {
            $code = trim((string) $row->{$column});
            if ($code !== '') {
                $owners[HierarchyName::key($code)]['id:'.$row->id] = true;
            }
        }

        return $owners;
    }

    private function maximumDatabaseCodeSequence(
        Collection $rows,
        string $column,
        string $type,
        string $period,
    ): int {
        $maximum = 0;

        foreach ($rows as $row) {
            $sequence = $this->databaseCodeSequence($row->{$column}, $type, $period);
            if ($sequence !== null) {
                $maximum = max($maximum, $sequence);
            }
        }

        return $maximum;
    }

    private function databaseCodeSequence(mixed $code, string $type, string $period): ?int
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        try {
            $parsed = match ($type) {
                'sub_department' => ProductHierarchyCode::parseSubDepartment($code),
                'sub_sub_department' => ProductHierarchyCode::parseSubSubDepartment($code),
                default => throw new InvalidArgumentException('Unknown hierarchy database-code type.'),
            };
        } catch (InvalidArgumentException) {
            return null;
        }

        return $parsed['period'] === ProductHierarchyCode::normalizePeriod($period)
            ? (int) $parsed['sequence']
            : null;
    }

    /** @return array{actual_code: ?string, canonical_code: string, code: ?string, legacy_code: bool, conflict: bool} */
    private function evaluateCode(
        array &$issues,
        array &$owners,
        string $type,
        string $name,
        int $row,
        string $canonicalCode,
        mixed $actualCode,
        mixed $databaseId,
        bool $isNew,
        string $plannedOwner,
    ): array {
        $actual = trim((string) $actualCode);
        $actual = $actual === '' ? null : $actual;
        $canonicalKey = HierarchyName::key($canonicalCode);
        $allowedOwner = $databaseId ? 'id:'.$databaseId : null;
        $foreignOwners = array_filter(
            array_keys($owners[$canonicalKey] ?? []),
            fn (string $owner): bool => $owner !== $allowedOwner,
        );
        $conflict = $foreignOwners !== [];

        if ($conflict) {
            $this->issue(
                $issues,
                'error',
                $row,
                'generated_code_collision',
                "Canonical {$type} code {$canonicalCode} for {$name} is already assigned to another record."
            );
        }

        if ($isNew) {
            $owners[$canonicalKey]['planned:'.$plannedOwner] = true;
        }

        $legacy = ! $isNew && $actual !== $canonicalCode;
        if ($legacy) {
            $message = $actual === null
                ? "Existing {$type} {$name} has no code; it will keep that value. Its canonical code is {$canonicalCode}."
                : "Existing {$type} {$name} keeps legacy code {$actual}; its canonical code is {$canonicalCode}.";
            $this->issue($issues, 'warning', $row, 'legacy_hierarchy_code', $message);
        }

        return [
            'actual_code' => $actual,
            'canonical_code' => $canonicalCode,
            'code' => $isNew ? $canonicalCode : $actual,
            'legacy_code' => $legacy,
            'conflict' => $conflict,
        ];
    }

    private function allocateSequence(
        int &$maximum,
        array &$issues,
        int $row,
        string $code,
        string $message,
    ): ?int {
        if ($maximum >= 999999) {
            $this->issue($issues, 'error', $row, $code, $message);

            return null;
        }

        return ++$maximum;
    }

    private function storedSequence(mixed $value, string $label): int
    {
        if (! is_int($value) && ! ctype_digit((string) $value)) {
            throw new InvalidArgumentException("{$label} must be an integer.");
        }

        $sequence = (int) $value;
        ProductHierarchyCode::sequence($sequence);

        return $sequence;
    }

    /** @param array<string, array<string, mixed>> $plan */
    private function publicHierarchy(array $plan): array
    {
        return array_values(array_map(function (array $department): array {
            $department['sequence'] = $department['main_sequence'];
            unset($department['database_id'], $department['first_row'], $department['link_fields']);
            $department['sub_departments'] = array_values(array_map(function (array $sub): array {
                $sub['sequence'] = $sub['sub_sequence'];
                unset($sub['database_id'], $sub['first_row'], $sub['link_fields']);
                $sub['sub_sub_departments'] = array_values(array_map(function (array $leaf): array {
                    $leaf['sequence'] = $leaf['sub_sub_sequence'];
                    unset($leaf['database_id'], $leaf['first_row'], $leaf['link_fields']);

                    return $leaf;
                }, $sub['sub_sub_departments']));

                return $sub;
            }, $department['sub_departments']));

            return $department;
        }, $plan));
    }

    private function assertDepartmentMatchesPlan(ProductDepartments $model, array $plan): void
    {
        if (
            HierarchyName::key($model->Product_Department_Name) !== HierarchyName::key($plan['name'])
            || $this->nullableString($model->Product_Department_Code) !== $plan['actual_code']
        ) {
            throw new RuntimeException('An existing department name or code changed after preview.', 409);
        }

        $linkFields = $plan['link_fields'];
        $sourceMainId = $this->nullableString($model->Source_Main_Id);
        if (in_array('source_main_id', $linkFields, true)) {
            if ($sourceMainId !== null) {
                throw new RuntimeException('Existing department M-Id metadata changed after preview.', 409);
            }
        } else {
            try {
                $storedMain = $sourceMainId === null ? null : ProductHierarchyCode::parseMainId($sourceMainId);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('Existing department M-Id metadata changed after preview.', 409, $exception);
            }
            if ($storedMain === null || $storedMain['sequence'] !== (int) $plan['main_sequence']) {
                throw new RuntimeException('Existing department M-Id metadata changed after preview.', 409);
            }
        }

        if (in_array('source_main_sequence', $linkFields, true)) {
            if ($model->Source_Main_Sequence !== null) {
                throw new RuntimeException('Existing department main sequence changed after preview.', 409);
            }
        } elseif (
            $model->Source_Main_Sequence === null
            || (int) $model->Source_Main_Sequence !== (int) $plan['main_sequence']
        ) {
            throw new RuntimeException('Existing department main sequence changed after preview.', 409);
        }

        $storedPeriod = $this->nullableString($model->Hierarchy_Code_Period);
        if (in_array('hierarchy_code_period', $linkFields, true)) {
            if ($storedPeriod !== null) {
                throw new RuntimeException('Existing department code period changed after preview.', 409);
            }
        } else {
            try {
                $storedPeriod = $storedPeriod === null ? null : ProductHierarchyCode::normalizePeriod($storedPeriod);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('Existing department code period changed after preview.', 409, $exception);
            }
            if ($storedPeriod !== $plan['code_period']) {
                throw new RuntimeException('Existing department code period changed after preview.', 409);
            }
        }
    }

    private function assertChildMatchesPlan(
        Model $model,
        array $plan,
        string $parentColumn,
        int $parentId,
        string $nameColumn,
        string $codeColumn,
        string $sequenceColumn,
        string $sequencePlanKey,
        ?string $slugColumn = null,
    ): void {
        if (
            (int) $model->{$parentColumn} !== $parentId
            || HierarchyName::fingerprint($model->{$nameColumn}) !== HierarchyName::fingerprint($plan['name'])
            || $this->nullableString($model->{$codeColumn}) !== $plan['actual_code']
        ) {
            throw new RuntimeException('An existing child category identity changed after preview.', 409);
        }

        if (
            $slugColumn !== null
            && $this->nullableString($model->{$slugColumn}) !== $this->nullableString($plan['slug'])
        ) {
            throw new RuntimeException('An existing child category slug changed after preview.', 409);
        }

        $linkField = $sequencePlanKey === 'sub_sequence'
            ? 'source_sub_sequence'
            : 'source_sub_sub_sequence';
        if (in_array($linkField, $plan['link_fields'], true)) {
            if ($model->{$sequenceColumn} !== null) {
                throw new RuntimeException('Existing child category sequence changed after preview.', 409);
            }

            return;
        }

        if (
            $model->{$sequenceColumn} === null
            || (int) $model->{$sequenceColumn} !== (int) $plan[$sequencePlanKey]
        ) {
            throw new RuntimeException('Existing child category sequence changed after preview.', 409);
        }
    }

    private function linkDepartmentMetadata(ProductDepartments $model, array $plan): void
    {
        foreach ($plan['link_fields'] as $field) {
            match ($field) {
                'source_main_id' => $this->setDepartmentMetadata($model, 'Source_Main_Id', $plan['main_id']),
                'source_main_sequence' => $this->setDepartmentMetadata($model, 'Source_Main_Sequence', $plan['main_sequence']),
                'hierarchy_code_period' => $this->setDepartmentMetadata($model, 'Hierarchy_Code_Period', $plan['code_period']),
                default => throw new RuntimeException('The hierarchy plan contains an unknown department metadata field.', 409),
            };
        }
        $model->save();
    }

    private function setDepartmentMetadata(ProductDepartments $model, string $column, mixed $value): void
    {
        if ($this->nullableString($model->{$column}) !== null) {
            throw new RuntimeException('Existing department metadata changed after preview.', 409);
        }

        $model->{$column} = $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function linkSequenceMetadata(Model $model, string $column, int $sequence): void
    {
        if ($model->{$column} !== null && (int) $model->{$column} !== $sequence) {
            throw new RuntimeException('Existing hierarchy sequence metadata changed after preview.', 409);
        }
        if ($model->{$column} === null) {
            $model->{$column} = $sequence;
            $model->save();
        }
    }

    private function acquireImportLock(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            $result = DB::selectOne("DECLARE @result INT; EXEC @result = sp_getapplock @Resource = 'product-hierarchy-import', @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000; SELECT @result AS result;");
            if (! $result || (int) $result->result < 0) {
                throw new RuntimeException('Another hierarchy import is currently being committed. Please retry.', 409);
            }
        }
    }

    private function slug(string $department, string $sub, string $leaf, string $identity): string
    {
        $base = Str::slug($department.'-'.$sub.'-'.$leaf) ?: 'category';
        $suffix = substr(hash('sha256', HierarchyName::key($identity)), 0, 24);

        return substr($base, 0, 230).'-'.$suffix;
    }

    private function issue(array &$issues, string $severity, int $row, string $code, string $message): void
    {
        $issues[] = compact('severity', 'row', 'code', 'message');
    }
}
