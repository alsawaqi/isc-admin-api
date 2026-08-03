<?php

namespace Tests\Unit\Products;

require_once dirname(__DIR__, 2).'/Support/MinimalXlsxFactory.php';

use App\Services\ProductHierarchyXlsxParser;
use App\Support\HierarchyName;
use DOMDocument;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\MinimalXlsxFactory;
use ZipArchive;

final class ProductHierarchyXlsxParserTest extends TestCase
{
    private string $temporaryDirectory;

    private int $workbookSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class) || ! class_exists(DOMDocument::class)) {
            $this->markTestSkipped('The zip and DOM extensions are required for XLSX parser tests.');
        }

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'isc-hierarchy-tests-'.bin2hex(random_bytes(8));
        if (! mkdir($this->temporaryDirectory, 0700, true) && ! is_dir($this->temporaryDirectory)) {
            throw new RuntimeException('Unable to create a temporary XLSX test directory.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_helper_formulas_outside_imported_columns_are_ignored(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => [
                'A2' => ['formula' => 'ROW()', 'value' => '2'],
                'C2' => 'MAIN-0001',
                'D2' => 'Pneumatics',
                'E2' => ['formula' => 'IF(1=1,"Yes","No")', 'value' => 'Yes'],
                'F2' => 'Air Compressors',
                'G2' => 'Screw Air Compressors',
                'H2' => ['formula' => 'LEN(G2)', 'value' => '21'],
            ],
        ]);

        $this->assertSame(1, $result['valid_paths']);
        $this->assertSame([], $result['issues']);
    }

    #[DataProvider('hierarchyFormulaCells')]
    public function test_formulas_in_hierarchy_columns_are_rejected(string $reference, string $cachedValue): void
    {
        $row = [
            'C2' => 'MAIN-0001',
            'D2' => 'Pneumatics',
            'F2' => 'Air Compressors',
            'G2' => 'Screw Air Compressors',
        ];
        $row[$reference] = ['formula' => 'CONCAT("untrusted"," value")', 'value' => $cachedValue];

        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => $row,
        ]);

        $this->assertSame(0, $result['valid_paths']);
        $this->assertIssue($result, 'formula_in_hierarchy_cell', 2, 'error');
        $this->assertStringContainsString($reference, $result['issues'][0]['message']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function hierarchyFormulaCells(): iterable
    {
        yield 'M-Id' => ['C2', 'MAIN-0001'];
        yield 'Main Group' => ['D2', 'Pneumatics'];
        yield 'Sub Group' => ['F2', 'Air Compressors'];
        yield 'Sub Sub Category' => ['G2', 'Screw Air Compressors'];
    }

    public function test_blank_subgroups_inherit_within_a_main_group_and_across_visual_spacers(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => ' Pneumatics ', 'F2' => 'Air   Compressors', 'G2' => 'Piston Air Compressors'],
            3 => ['C3' => 'MAIN-0001', 'D3' => 'Pneumatics', 'G3' => 'Screw Air Compressors'],
            4 => ['C4' => 'MAIN-0001', 'D4' => 'Pneumatics'],
            5 => ['C5' => 'MAIN-0001', 'D5' => 'Pneumatics', 'G5' => 'Portable Air Compressors'],
        ]);

        $this->assertSame(4, $result['rows_read']);
        $this->assertSame(1, $result['separator_rows']);
        $this->assertSame(3, $result['valid_paths']);
        $this->assertSame(1, $result['departments']);
        $this->assertSame(1, $result['sub_departments']);
        $this->assertSame([], $result['issues']);

        $department = array_values($result['hierarchy'])[0];
        $subDepartment = array_values($department['sub_departments'])[0];
        $this->assertSame(1, $department['main_sequence']);
        $this->assertSame('Pneumatics', $department['name']);
        $this->assertSame('Air Compressors', $subDepartment['name']);
        $this->assertSame(
            ['Piston Air Compressors', 'Screw Air Compressors', 'Portable Air Compressors'],
            array_column(array_values($subDepartment['sub_sub_departments']), 'name'),
        );
    }

    public function test_a_fully_blank_row_resets_subgroup_inheritance(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            3 => [],
            4 => ['C4' => 'MAIN-0001', 'D4' => 'Pneumatics', 'G4' => 'Must Not Inherit'],
        ]);

        $this->assertSame(1, $result['separator_rows']);
        $this->assertSame(1, $result['valid_paths']);
        $this->assertIssue($result, 'missing_sub_group', 4, 'error');
    }

    public function test_subgroup_inheritance_resets_when_the_main_id_changes(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            3 => ['C3' => 'MAIN-0002', 'D3' => 'Electrical', 'G3' => 'Circuit Breakers'],
        ]);

        $this->assertSame(1, $result['valid_paths']);
        $this->assertSame(2, $result['departments']);
        $this->assertIssue($result, 'missing_sub_group', 3, 'error');
    }

    public function test_a_malformed_row_resets_inherited_subgroup_state(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            3 => ['D3' => 'Pneumatics', 'G3' => 'Malformed Leaf'],
            4 => ['C4' => 'MAIN-0001', 'D4' => 'Pneumatics', 'G4' => 'Must Not Inherit'],
        ]);

        $this->assertSame(1, $result['valid_paths']);
        $this->assertIssue($result, 'missing_main_id', 3, 'error');
        $this->assertIssue($result, 'missing_sub_group', 4, 'error');
    }

    public function test_a_blank_leaf_creates_only_the_department_and_subgroup(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors'],
        ]);

        $this->assertSame(1, $result['departments']);
        $this->assertSame(1, $result['sub_departments']);
        $this->assertSame(0, $result['sub_sub_departments']);
        $this->assertSame(1, $result['blank_leaf_rows']);
    }

    public function test_duplicate_full_paths_are_warned_and_deduplicated_after_normalization(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            3 => ['C3' => 'MAIN-0001', 'D3' => 'PNEUMATICS', 'F3' => " Air\u{00A0} Compressors ", 'G3' => ' screw   air compressors '],
        ]);

        $this->assertSame(1, $result['valid_paths']);
        $this->assertSame(1, $result['duplicate_paths']);
        $this->assertIssue($result, 'duplicate_path', 3, 'warning');
    }

    public function test_unicode_equivalent_full_paths_are_deduplicated(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Café Equipment', 'F2' => 'Pumps', 'G2' => 'Café Pump'],
            3 => ['C3' => 'MAIN-0001', 'D3' => "Cafe\u{0301} Equipment", 'F3' => 'Pumps', 'G3' => "Cafe\u{0301} Pump"],
        ]);

        $this->assertSame(1, $result['valid_paths']);
        $this->assertSame(1, $result['duplicate_paths']);
        $this->assertIssue($result, 'duplicate_path', 3, 'warning');
    }

    public function test_the_same_leaf_name_under_different_subgroups_is_valid(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Industrial', 'F2' => 'Electrical Cleaning', 'G2' => 'Degreasers'],
            3 => ['C3' => 'MAIN-0001', 'D3' => 'Industrial', 'F3' => 'Mechanical Cleaning', 'G3' => 'Degreasers'],
        ]);

        $this->assertSame(2, $result['valid_paths']);
        $this->assertSame(2, $result['sub_departments']);
        $this->assertSame(0, $result['duplicate_paths']);
        $this->assertSame([], $result['issues']);
    }

    #[DataProvider('malformedRows')]
    public function test_malformed_rows_produce_blocking_issues(array $row, string $issueCode): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => $row,
        ]);

        $this->assertIssue($result, $issueCode, 2, 'error');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function malformedRows(): iterable
    {
        yield 'missing M-Id' => [
            ['D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            'missing_main_id',
        ];
        yield 'invalid M-Id' => [
            ['C2' => 'MAIN 0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            'invalid_main_id',
        ];
        yield 'lowercase M-Id' => [
            ['C2' => 'main-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            'invalid_main_id',
        ];
        yield 'missing main group' => [
            ['C2' => 'MAIN-0001', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            'missing_main_group',
        ];
        yield 'missing subgroup' => [
            ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'G2' => 'Screw Air Compressors'],
            'missing_sub_group',
        ];
        yield 'overlong name' => [
            ['C2' => 'MAIN-0001', 'D2' => str_repeat('A', 256), 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            'name_too_long',
        ];
    }

    public function test_inconsistent_main_id_and_name_mappings_are_blocking(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            3 => ['C3' => 'MAIN-0001', 'D3' => 'Electrical', 'F3' => 'Switchgear', 'G3' => 'Breakers'],
            4 => ['C4' => 'MAIN-0002', 'D4' => 'PNEUMATICS', 'F4' => 'Hoses', 'G4' => 'Coiled Hoses'],
        ]);

        $this->assertIssue($result, 'main_id_name_conflict', 3, 'error');
        $this->assertIssue($result, 'main_name_id_conflict', 4, 'error');
    }

    public function test_numeric_main_id_aliases_are_blocking(): void
    {
        $result = $this->parse([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Piston Compressors'],
            3 => ['C3' => 'MAIN-000001', 'D3' => 'Pneumatics', 'F3' => 'Air Compressors', 'G3' => 'Screw Compressors'],
        ]);

        $this->assertSame(1, $result['valid_paths']);
        $this->assertIssue($result, 'main_id_alias_conflict', 3, 'error');
    }

    public function test_hierarchy_name_normalization_is_pure_and_deterministic(): void
    {
        $decomposed = " \u{FEFF}Cafe\u{0301}\u{00A0}\t Tools\u{200B}\x07 ";

        $this->assertSame('Café Tools', HierarchyName::display($decomposed));
        $this->assertSame('café tools', HierarchyName::key($decomposed));
        $this->assertSame(HierarchyName::fingerprint($decomposed), HierarchyName::fingerprint('CAFÉ  TOOLS'));
        $this->assertNotSame(HierarchyName::fingerprint($decomposed), HierarchyName::fingerprint('Cafe Tools'));
    }

    public function test_archive_with_a_parent_traversal_entry_is_rejected(): void
    {
        $this->expectUnsafeArchive(
            ['../outside.bin' => 'not allowed'],
            'The XLSX archive contains an unsafe path.',
        );
    }

    public function test_macro_enabled_archive_content_is_rejected(): void
    {
        $this->expectUnsafeArchive(
            ['xl/vbaProject.bin' => 'macro bytes'],
            'Macro-enabled, encrypted, embedded, or externally linked workbooks are not accepted.',
        );
    }

    public function test_external_relationships_are_rejected(): void
    {
        $relationships = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId9" Type="urn:test" Target="https://attacker.invalid/file" TargetMode="External"/>
</Relationships>
XML;

        $this->expectUnsafeArchive(
            ['xl/worksheets/_rels/sheet1.xml.rels' => $relationships],
            'Workbooks containing external relationships are not accepted.',
        );
    }

    public function test_dtd_or_entity_declarations_are_rejected_before_xml_parsing(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE root [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<root>&xxe;</root>
XML;

        $this->expectUnsafeArchive(
            ['docProps/custom.xml' => $xml],
            'The XLSX archive contains unsafe XML.',
        );
    }

    public function test_high_compression_ratio_archive_entries_are_rejected(): void
    {
        $this->expectUnsafeArchive(
            ['xl/media/padding.bin' => str_repeat('A', 2 * 1024 * 1024)],
            'The XLSX archive has an unsafe compression ratio.',
        );
    }

    public function test_archive_entry_count_is_bounded(): void
    {
        $entries = [];
        for ($index = 0; $index < 247; $index++) {
            $entries["docProps/filler-{$index}.bin"] = 'x';
        }

        $this->expectUnsafeArchive(
            $entries,
            'The XLSX archive contains an unsafe number of entries.',
        );
    }

    public function test_worksheet_row_element_count_is_bounded_even_when_declared_numbers_repeat(): void
    {
        $header = '<row r="1">'
            .'<c r="C1" t="inlineStr"><is><t>M-Id</t></is></c>'
            .'<c r="D1" t="inlineStr"><is><t>Main Group</t></is></c>'
            .'<c r="F1" t="inlineStr"><is><t>Sub Group</t></is></c>'
            .'<c r="G1" t="inlineStr"><is><t>Sub Sub Categories</t></is></c>'
            .'</row>';
        $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$header
            .str_repeat('<row r="2"/>', 20_000)
            .'</sheetData></worksheet>';
        $path = $this->workbook([], ['xl/worksheets/sheet1.xml' => $worksheet]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The workbook exceeds the maximum supported number of rows.');
        (new ProductHierarchyXlsxParser)->parse($path);
    }

    public function test_a_single_oversized_row_is_rejected_before_dom_expansion(): void
    {
        $path = $this->workbook([
            1 => MinimalXlsxFactory::hierarchyHeader(),
            2 => [
                'C2' => 'MAIN-0001',
                'D2' => 'Pneumatics',
                'F2' => 'Air Compressors',
                'G2' => str_repeat('A', 300_000),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A worksheet row is too large or complex.');
        (new ProductHierarchyXlsxParser)->parse($path);
    }

    public function test_missing_required_workbook_parts_are_rejected(): void
    {
        $path = $this->workbook(
            [1 => MinimalXlsxFactory::hierarchyHeader()],
            [],
            ['xl/_rels/workbook.xml.rels'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The archive is missing required XLSX workbook data.');
        (new ProductHierarchyXlsxParser)->parse($path);
    }

    /**
     * @param  array<int, array<string, string|array{formula: string, value?: string}>>  $rows
     * @param  array<string, string>  $extraEntries
     * @param  list<string>  $removeEntries
     */
    private function workbook(array $rows, array $extraEntries = [], array $removeEntries = []): string
    {
        $this->workbookSequence++;

        return MinimalXlsxFactory::write(
            $this->temporaryDirectory.DIRECTORY_SEPARATOR."workbook-{$this->workbookSequence}.xlsx",
            $rows,
            $extraEntries,
            $removeEntries,
        );
    }

    /** @param array<int, array<string, string|array{formula: string, value?: string}>> $rows */
    private function parse(array $rows): array
    {
        return (new ProductHierarchyXlsxParser)->parse($this->workbook($rows));
    }

    /** @param array<string, string> $entries */
    private function expectUnsafeArchive(array $entries, string $message): void
    {
        $path = $this->workbook(
            [
                1 => MinimalXlsxFactory::hierarchyHeader(),
                2 => ['C2' => 'MAIN-0001', 'D2' => 'Pneumatics', 'F2' => 'Air Compressors', 'G2' => 'Screw Air Compressors'],
            ],
            $entries,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new ProductHierarchyXlsxParser)->parse($path);
    }

    private function assertIssue(array $result, string $code, int $row, string $severity): void
    {
        $matches = array_values(array_filter(
            $result['issues'],
            fn (array $issue): bool => $issue['code'] === $code
                && $issue['row'] === $row
                && $issue['severity'] === $severity,
        ));

        $this->assertCount(1, $matches, "Expected one {$severity} issue {$code} on row {$row}.");
    }
}
