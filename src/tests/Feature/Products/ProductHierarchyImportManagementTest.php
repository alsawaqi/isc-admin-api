<?php

namespace Tests\Feature\Products;

use App\Models\ProductDepartments;
use App\Models\ProductHierarchyImportJob;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use App\Models\SecurityPermission;
use App\Models\User;
use App\Support\ProductHierarchyCode;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\FeatureTestCase;
use ZipArchive;

class ProductHierarchyImportManagementTest extends FeatureTestCase
{
    public function test_committed_import_can_be_rolled_back_when_unused(): void
    {
        $user = $this->actingAsImportAdmin();
        [$department, $subDepartment, $leaf] = $this->createHierarchy($user);
        $job = $this->createCommittedImportJob($user, $department->id, $subDepartment->id, $leaf->id);

        $response = $this->postJson('/api/product-hierarchy-import/'.$job->id.'/rollback');

        $response->assertOk()
            ->assertJsonPath('data.deleted.departments', 1)
            ->assertJsonPath('data.deleted.sub_departments', 1)
            ->assertJsonPath('data.deleted.sub_sub_departments', 1);

        $this->assertDatabaseMissing('Products_Sub_Sub_Department_T', ['id' => $leaf->id]);
        $this->assertDatabaseMissing('Products_Sub_Department_T', ['id' => $subDepartment->id]);
        $this->assertDatabaseMissing('Products_Departments_T', ['id' => $department->id]);
        $this->assertDatabaseHas('Product_Hierarchy_Import_Jobs_T', [
            'id' => $job->id,
            'Status' => 'rolled_back',
        ]);
    }

    public function test_rollback_is_rejected_when_imported_category_is_in_use(): void
    {
        $user = $this->actingAsImportAdmin();
        [$department, $subDepartment, $leaf] = $this->createHierarchy($user);
        $job = $this->createCommittedImportJob($user, $department->id, $subDepartment->id, $leaf->id);

        DB::table('Products_Discounts_T')->insert([
            'Product_Discount_Code' => 'DISC_TEST_'.uniqid(),
            'Product_Discount_Name' => 'Rollback Guard Discount '.uniqid(),
            'Target_Type' => 'department',
            'Product_Department_Id' => $department->id,
            'Product_Discount_Type' => 'percent',
            'Product_Discount_Value' => 5,
            'Product_Discount_Is_Active' => true,
            'Created_By' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/product-hierarchy-import/'.$job->id.'/rollback')
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('Products_Departments_T', ['id' => $department->id]);
        $this->assertDatabaseHas('Product_Hierarchy_Import_Jobs_T', [
            'id' => $job->id,
            'Status' => 'committed',
        ]);
    }

    public function test_hierarchy_export_returns_excel_with_names_and_codes(): void
    {
        $user = $this->actingAsImportAdmin();
        [$department, $subDepartment, $leaf] = $this->createHierarchy($user);
        $secondLeafCode = ProductHierarchyCode::subSubDepartment('2026-08', random_int(900000, 999999));
        ProductSubSubDepartment::create([
            'Product_Sub_Department_Id' => $subDepartment->id,
            'Product_Sub_Sub_Department_Code' => $secondLeafCode,
            'Source_Sub_Sub_Sequence' => 2,
            'Product_Sub_Sub_Department_Name' => 'Second Export Leaf '.uniqid(),
            'Product_Sub_Sub_Department_Name_Ar' => null,
            'Slug' => 'second-export-leaf-'.strtolower(str_replace('.', '', uniqid('', true))),
            'Created_Date' => now(),
            'Created_By' => $user->id,
        ]);

        $response = $this->get('/api/product-hierarchy-import/export');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );

        $path = tempnam(sys_get_temp_dir(), 'hierarchy-export-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $response->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertIsString($sheet);
        $this->assertStringContainsString('<t>No</t>', $sheet);
        $this->assertStringContainsString('<t>Sub Sub Categories</t>', $sheet);
        $this->assertStringContainsString($department->Product_Department_Name, $sheet);
        $this->assertStringContainsString($department->Product_Department_Code, $sheet);
        $this->assertStringContainsString(
            ProductHierarchyCode::exportSubDepartment(
                $department->Hierarchy_Code_Period,
                $department->Source_Main_Sequence,
                $subDepartment->Source_Sub_Sequence,
            ),
            $sheet,
        );
        $this->assertStringContainsString(
            ProductHierarchyCode::exportSubSubDepartment(
                $department->Hierarchy_Code_Period,
                $department->Source_Main_Sequence,
                $subDepartment->Source_Sub_Sequence,
                1,
            ),
            $sheet,
        );
        $this->assertStringContainsString(
            ProductHierarchyCode::exportSubSubDepartment(
                $department->Hierarchy_Code_Period,
                $department->Source_Main_Sequence,
                $subDepartment->Source_Sub_Sequence,
                2,
            ),
            $sheet,
        );
        $this->assertStringNotContainsString($subDepartment->Products_Sub_Department_Code, $sheet);
        $this->assertStringNotContainsString($leaf->Product_Sub_Sub_Department_Code, $sheet);
        $this->assertStringNotContainsString($secondLeafCode, $sheet);
        $this->assertSame(1, substr_count($sheet, $department->Product_Department_Name));
        $this->assertSame(1, substr_count($sheet, $subDepartment->Sub_Department_Name));
    }

    private function actingAsImportAdmin(): User
    {
        $user = $this->actingAsAdmin();
        $permission = SecurityPermission::query()->firstOrCreate([
            'name' => 'import product categories',
            'guard_name' => 'sanctum',
        ]);
        $user->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @return array{ProductDepartments, ProductSubDepartment, ProductSubSubDepartment} */
    private function createHierarchy(User $user): array
    {
        $suffix = strtoupper(substr(str_replace('.', '', uniqid('', true)), -10));
        $sequence = random_int(700000, 799999);
        $storageSequence = random_int(800000, 899999);
        $department = ProductDepartments::create([
            'Product_Department_Code' => 'DEPT_2026_AUG_MAIN_'.$sequence,
            'Source_Main_Id' => 'MAIN-'.$sequence,
            'Source_Main_Sequence' => $sequence,
            'Hierarchy_Code_Period' => '2026-08',
            'Product_Department_Name' => 'Rollback Dept '.$suffix,
            'Product_Department_Name_Ar' => null,
            'Created_Date' => now(),
            'Created_By' => $user->id,
        ]);
        $subDepartment = ProductSubDepartment::create([
            'Products_Departments_Id' => $department->id,
            'Products_Sub_Department_Code' => 'SUBDEPT_2026_AUG_SUB_'.$storageSequence,
            'Source_Sub_Sequence' => 1,
            'Sub_Department_Name' => 'Rollback Sub '.$suffix,
            'Sub_Department_Name_Ar' => null,
            'Created_Date' => now(),
            'Created_By' => $user->id,
        ]);
        $leaf = ProductSubSubDepartment::create([
            'Product_Sub_Department_Id' => $subDepartment->id,
            'Product_Sub_Sub_Department_Code' => 'SUBSUBDEPT_2026_AUG_SUBSUB_'.$storageSequence,
            'Source_Sub_Sub_Sequence' => 1,
            'Product_Sub_Sub_Department_Name' => 'Rollback Leaf '.$suffix,
            'Product_Sub_Sub_Department_Name_Ar' => null,
            'Slug' => 'rollback-leaf-'.strtolower($suffix),
            'Created_Date' => now(),
            'Created_By' => $user->id,
        ]);

        return [$department, $subDepartment, $leaf];
    }

    private function createCommittedImportJob(User $user, int $departmentId, int $subDepartmentId, int $leafId): ProductHierarchyImportJob
    {
        $payload = '{}';
        $result = [
            'code_period' => '2026-08',
            'created' => ['departments' => 1, 'sub_departments' => 1, 'sub_sub_departments' => 1],
            'skipped' => ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0],
            'linked' => ['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 0],
            'created_ids' => [
                'departments' => [$departmentId],
                'sub_departments' => [$subDepartmentId],
                'sub_sub_departments' => [$leafId],
            ],
            'linked_records' => ['departments' => [], 'sub_departments' => [], 'sub_sub_departments' => []],
            'errors' => 0,
        ];

        return ProductHierarchyImportJob::create([
            'Token' => (string) str()->uuid(),
            'User_Id' => $user->id,
            'File_Name' => 'rollback-test.xlsx',
            'File_Size' => 1234,
            'File_Sha256' => hash('sha256', 'rollback-test'),
            'Payload_Digest' => hash('sha256', $payload),
            'Canonical_Payload' => $payload,
            'Summary' => json_encode(['code_period' => '2026-08'], JSON_THROW_ON_ERROR),
            'Status' => 'committed',
            'Can_Commit' => true,
            'Expires_At' => now()->addHour(),
            'Committed_At' => now(),
            'Result' => json_encode($result, JSON_THROW_ON_ERROR),
        ]);
    }
}
