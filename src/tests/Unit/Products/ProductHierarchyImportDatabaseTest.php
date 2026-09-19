<?php

namespace Tests\Unit\Products;

require_once dirname(__DIR__, 2).'/Support/MinimalXlsxFactory.php';

use App\Models\ProductHierarchyImportJob;
use App\Services\ProductHierarchyImportService;
use App\Services\ProductHierarchyXlsxParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MinimalXlsxFactory;
use Tests\TestCase;

final class ProductHierarchyImportDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'hierarchy_import_test',
            'database.connections.hierarchy_import_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('hierarchy_import_test');
        DB::setDefaultConnection('hierarchy_import_test');

        Schema::create('Products_Departments_T', function (Blueprint $t): void {
            $t->id();
            foreach (['Product_Department_Code', 'Product_Department_Name', 'Product_Department_Name_Ar', 'Source_Main_Id', 'Hierarchy_Code_Period'] as $column) {
                $t->string($column)->nullable();
            }
            $t->integer('Source_Main_Sequence')->nullable();
            $t->bigInteger('Display_Order');
            $t->dateTime('Created_Date')->nullable();
            $t->integer('Created_By')->nullable();
            $t->timestamps();
        });
        foreach ([
            ['Products_Sub_Department_T', 'Products_Departments_Id', 'Products_Sub_Department_Code', 'Sub_Department_Name', 'Source_Sub_Sequence'],
            ['Products_Sub_Sub_Department_T', 'Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Code', 'Product_Sub_Sub_Department_Name', 'Source_Sub_Sub_Sequence'],
        ] as [$table, $parent, $code, $name, $sequence]) {
            Schema::create($table, function (Blueprint $t) use ($parent, $code, $name, $sequence): void {
                $t->id();
                $t->unsignedBigInteger($parent);
                $t->string($code)->unique();
                $t->string($name);
                $t->string($name.'_Ar')->nullable();
                $t->integer($sequence)->nullable();
                $t->string('Name_Fingerprint')->nullable();
                $t->string('Slug')->nullable();
                $t->bigInteger('Display_Order');
                $t->dateTime('Created_Date')->nullable();
                $t->integer('Created_By')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('Product_Hierarchy_Display_Order_State_T', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('Revision')->default(1);
        });
        Schema::create('Product_Hierarchy_Import_Jobs_T', function (Blueprint $t): void {
            $t->id();
            $t->uuid('Token')->unique();
            $t->integer('User_Id');
            foreach (['File_Name', 'File_Sha256', 'Payload_Digest', 'Status'] as $column) {
                $t->string($column);
            }
            $t->integer('File_Size');
            foreach (['Canonical_Payload', 'Summary', 'Result'] as $column) {
                $t->text($column)->nullable();
            }
            $t->boolean('Can_Commit');
            $t->dateTime('Expires_At');
            $t->dateTime('Committed_At')->nullable();
            $t->dateTime('Rolled_Back_At')->nullable();
            $t->integer('Rolled_Back_By')->nullable();
            $t->timestamps();
        });
        DB::table('Product_Hierarchy_Display_Order_State_T')->insert(['id' => 1, 'Revision' => 1]);
        // Identical names in other branches must not steal a match from this parent.
        foreach ([1, 2] as $id) {
            DB::table('Products_Departments_T')->insert([
                'id' => $id, 'Product_Department_Code' => sprintf('DEPT_2026_AUG_MAIN_%06d', $id),
                'Product_Department_Name' => $id === 1 ? 'Pneumatics' : 'Other Department',
                'Source_Main_Id' => sprintf('MAIN-%04d', $id), 'Source_Main_Sequence' => $id,
                'Hierarchy_Code_Period' => '2026-08', 'Display_Order' => $id * 1_000_000_000,
            ]);
            DB::table('Products_Sub_Department_T')->insert([
                'id' => $id * 10, 'Products_Departments_Id' => $id,
                'Products_Sub_Department_Code' => sprintf('SUBDEPT_2026_AUG_SUB_%06d', $id),
                'Sub_Department_Name' => 'Air Compressors', 'Source_Sub_Sequence' => 1,
                'Display_Order' => 1_000_000_000,
            ]);
            DB::table('Products_Sub_Sub_Department_T')->insert([
                'id' => $id * 100, 'Product_Sub_Department_Id' => $id * 10,
                'Product_Sub_Sub_Department_Code' => sprintf('SUBSUBDEPT_2026_AUG_SUBSUB_%06d', $id),
                'Product_Sub_Sub_Department_Name' => 'Existing Compressor', 'Source_Sub_Sub_Sequence' => 1,
                'Display_Order' => 1_000_000_000, 'Slug' => 'existing-compressor-'.$id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('hierarchy_import_test');
        parent::tearDown();
    }

    public function test_import_reuses_existing_parents_and_leaves_and_only_rolls_back_new_leaves(): void
    {
        $service = new ProductHierarchyImportService;
        $before = $this->hierarchySnapshot();
        $parsed = $this->workbook();
        $analysis = $service->analyze($parsed);

        $this->assertTrue($analysis['can_commit'], json_encode($analysis['issues']));
        $this->assertSame(['departments' => 0, 'sub_departments' => 0, 'sub_sub_departments' => 1], $analysis['summary']['create']);
        $this->assertSame(['departments' => 1, 'sub_departments' => 1, 'sub_sub_departments' => 1], $analysis['summary']['existing']);
        $this->assertSame(0, array_sum($analysis['summary']['link']));
        $this->assertSame($before, $this->hierarchySnapshot(), 'Analysis must not write data.');

        $parsed['_allocation_digest'] = $service->planDigest($analysis);
        $payload = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $job = $this->job(['Canonical_Payload' => $payload, 'Payload_Digest' => hash('sha256', $payload)]);
        $result = $service->commit($job->Token, 1);
        $this->assertSame($analysis['summary']['create'], $result['created']);
        $this->assertSame($analysis['summary']['existing'], $result['skipped']);
        $this->assertSame([], $result['created_ids']['departments']);
        $this->assertSame([], $result['created_ids']['sub_departments']);
        $newId = $result['created_ids']['sub_sub_departments'][0];
        $this->assertDatabaseHas('Products_Sub_Sub_Department_T', [
            'id' => $newId, 'Product_Sub_Department_Id' => 10,
            'Product_Sub_Sub_Department_Name' => 'New Compressor', 'Source_Sub_Sub_Sequence' => 2,
        ]);
        $after = $this->hierarchySnapshot();
        foreach ($before as $table => $records) {
            foreach ($records as $id => $record) {
                $this->assertSame($record, $after[$table][$id]);
            }
        }
        $repeat = $service->analyze($this->workbook());
        $this->assertTrue($repeat['can_commit']);
        $this->assertSame(0, array_sum($repeat['summary']['create']));
        $this->assertSame(2, $repeat['summary']['existing']['sub_sub_departments']);
        $this->assertSame($result, $service->commit($job->Token, 1), 'Replaying the token must not duplicate rows.');

        $job->update(['Expires_At' => now()->subMonth()]);
        ProductHierarchyImportJob::pruneForNewPreview(1);
        $history = collect($service->history())->keyBy('id');
        $this->assertTrue($history[$job->id]['can_rollback']);
        $rollback = $service->rollback($job->id, 1);
        $this->assertSame(['sub_sub_departments' => 1, 'sub_departments' => 0, 'departments' => 0], $rollback['deleted']);
        $this->assertSame($before, $this->hierarchySnapshot());
    }

    #[DataProvider('sequenceConflicts')]
    public function test_invalid_or_duplicate_child_sequences_block_import(string $table, string $column, int $id, bool $duplicate, string $issue): void
    {
        if ($duplicate) {
            $row = (array) DB::table($table)->where('id', $id)->first();
            $row['id'] = 999;
            $isSub = $table === 'Products_Sub_Department_T';
            $row[$isSub ? 'Sub_Department_Name' : 'Product_Sub_Sub_Department_Name'] = 'Conflicting sibling';
            $row[$isSub ? 'Products_Sub_Department_Code' : 'Product_Sub_Sub_Department_Code'] = $isSub ? 'SUBDEPT_2026_AUG_SUB_000099' : 'SUBSUBDEPT_2026_AUG_SUBSUB_000099';
            DB::table($table)->insert($row);
        } else {
            DB::table($table)->where('id', $id)->update([$column => -1]);
        }
        $analysis = (new ProductHierarchyImportService)->analyze($this->workbook());
        $this->assertFalse($analysis['can_commit']);
        $this->assertContains($issue, array_column($analysis['issues'], 'code'));
    }

    public static function sequenceConflicts(): array
    {
        return [
            'duplicate subcategory sequence' => ['Products_Sub_Department_T', 'Source_Sub_Sequence', 10, true, 'sub_sequence_conflict'],
            'invalid subcategory sequence' => ['Products_Sub_Department_T', 'Source_Sub_Sequence', 10, false, 'sub_sequence_conflict'],
            'duplicate leaf sequence' => ['Products_Sub_Sub_Department_T', 'Source_Sub_Sub_Sequence', 100, true, 'sub_sub_sequence_conflict'],
            'invalid leaf sequence' => ['Products_Sub_Sub_Department_T', 'Source_Sub_Sub_Sequence', 100, false, 'sub_sub_sequence_conflict'],
        ];
    }

    public function test_preview_cleanup_preserves_completed_audit_records_regardless_of_age_or_count(): void
    {
        // A previously configured limit must not discard rollback metadata.
        config(['product_hierarchy_import.retained_jobs_per_user' => 1]);
        for ($i = 0; $i < 8; $i++) {
            $this->job([
                'User_Id' => $i % 2 + 1, 'Status' => $i % 3 === 0 ? 'rolled_back' : 'committed',
                'Expires_At' => now()->subYear(), 'Committed_At' => now()->subYear(),
                'Result' => json_encode(['created_ids' => ['sub_sub_departments' => [$i + 100]]]),
            ]);
        }
        $kept = ProductHierarchyImportJob::orderBy('id')->get()->toJson();
        $this->job(['Expires_At' => now()->subHour()]);
        $this->job();
        $this->job(['User_Id' => 2, 'Expires_At' => now()->subHour()]);
        $this->job(['User_Id' => 2, 'Status' => 'expired', 'Expires_At' => now()->subHour()]);
        $otherPending = $this->job(['User_Id' => 2]);

        ProductHierarchyImportJob::pruneForNewPreview(1);

        $this->assertSame($kept, ProductHierarchyImportJob::whereIn('Status', ['committed', 'rolled_back'])->orderBy('id')->get()->toJson());
        $this->assertSame([$otherPending->id], ProductHierarchyImportJob::whereNotIn('Status', ['committed', 'rolled_back'])->pluck('id')->all());
        $this->assertDatabaseCount('Product_Hierarchy_Import_Jobs_T', 9);
    }

    private function workbook(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'hierarchy-regression-');
        try {
            MinimalXlsxFactory::write($path, [
                1 => MinimalXlsxFactory::hierarchyHeader(),
                2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => '  AIR Compressors  ', 'G2' => 'existing compressor'],
                3 => ['G3' => 'New Compressor'],
            ]);

            return [...(new ProductHierarchyXlsxParser)->parse($path), 'code_period' => '2026-08'];
        } finally {
            unlink($path);
        }
    }

    private function job(array $attributes = []): ProductHierarchyImportJob
    {
        return ProductHierarchyImportJob::create(array_replace([
            'Token' => (string) str()->uuid(), 'User_Id' => 1, 'File_Name' => 'regression.xlsx',
            'File_Size' => 100, 'File_Sha256' => hash('sha256', 'test'),
            'Payload_Digest' => hash('sha256', '{}'), 'Canonical_Payload' => '{}', 'Summary' => '{}',
            'Status' => 'pending', 'Can_Commit' => true, 'Expires_At' => now()->addMinutes(15),
        ], $attributes));
    }

    private function hierarchySnapshot(): array
    {
        $snapshot = [];
        foreach (['Products_Departments_T', 'Products_Sub_Department_T', 'Products_Sub_Sub_Department_T'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
        }

        return $snapshot;
    }
}
