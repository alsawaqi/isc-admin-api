<?php

namespace Tests\Unit;

use App\Helpers\CodeGenerator;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeGeneratorTest extends TestCase
{
    private ?Container $previousApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $application = Facade::getFacadeApplication();
        $this->previousApplication = $application instanceof Container ? $application : null;
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(new Container);
        Carbon::setTestNow('2026-08-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        Mockery::close();

        parent::tearDown();
    }

    #[DataProvider('categoryCodes')]
    public function test_category_prefixes_use_the_requested_sequence_segment(
        string $prefix,
        string $table,
        string $column,
        string $segment,
    ): void {
        $pattern = "{$prefix}_2026_AUG_{$segment}_";
        $this->mockLatestCode($table, $column, $pattern, null);

        $this->assertSame(
            $pattern.'000001',
            CodeGenerator::createCode($prefix, $table, $column),
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function categoryCodes(): iterable
    {
        yield 'department' => ['DEPT', 'Products_Departments_T', 'Product_Department_Code', 'MAIN'];
        yield 'sub-department' => ['SUBDEPT', 'Products_Sub_Department_T', 'Products_Sub_Department_Code', 'SUB'];
        yield 'sub-sub-department' => ['SUBSUBDEPT', 'Products_Sub_Sub_Department_T', 'Product_Sub_Sub_Department_Code', 'SUBSUB'];
    }

    public function test_category_sequence_increments_the_latest_matching_code(): void
    {
        $pattern = 'SUBDEPT_2026_AUG_SUB_';
        $this->mockLatestCode(
            'Products_Sub_Department_T',
            'Products_Sub_Department_Code',
            $pattern,
            $pattern.'000009',
        );

        $this->assertSame(
            $pattern.'000010',
            CodeGenerator::createCode(
                'SUBDEPT',
                'Products_Sub_Department_T',
                'Products_Sub_Department_Code',
            ),
        );
    }

    public function test_non_category_prefixes_keep_the_existing_a_segment(): void
    {
        $pattern = 'PROD_2026_AUG_A_';
        $this->mockLatestCode('Products_Master_T', 'Product_Code', $pattern, null);

        $this->assertSame(
            $pattern.'000001',
            CodeGenerator::createCode('PROD', 'Products_Master_T', 'Product_Code'),
        );
    }

    private function mockLatestCode(string $table, string $column, string $pattern, ?string $latest): void
    {
        $query = Mockery::mock();
        $query->shouldReceive('where')->once()->with($column, 'like', $pattern.'%')->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with($column, 'desc')->andReturnSelf();
        $query->shouldReceive('value')->once()->with($column)->andReturn($latest);

        $database = Mockery::mock();
        $database->shouldReceive('table')->once()->with($table)->andReturn($query);
        Facade::getFacadeApplication()->instance('db', $database);
    }
}
