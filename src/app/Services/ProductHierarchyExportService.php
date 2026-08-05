<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

class ProductHierarchyExportService
{
    /** @return array{filename: string, contents: string} */
    public function export(): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to export Excel files.', 500);
        }

        $path = tempnam(sys_get_temp_dir(), 'isc-hierarchy-export-');
        if ($path === false) {
            throw new RuntimeException('The hierarchy export file could not be prepared.', 500);
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('The hierarchy export workbook could not be created.', 500);
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($this->hierarchyRows()));
        $zip->close();

        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new RuntimeException('The hierarchy export workbook could not be read.', 500);
        }

        return [
            'filename' => 'product-hierarchy-'.now()->format('Ymd-His').'.xlsx',
            'contents' => $contents,
        ];
    }

    /** @return array<int, array<int, string>> */
    public function hierarchyRows(): array
    {
        $rows = [[
            'No',
            'M-Id',
            'Main Group',
            'Product_Department_Code',
            'Sub Group',
            'Products_Sub_Department_Code',
            'Sub Sub Categories',
            'Product_Sub_Sub_Department_Code',
        ]];

        $previousDepartmentId = null;
        $previousSubDepartmentId = null;

        DB::table('Products_Departments_T as d')
            ->leftJoin('Products_Sub_Department_T as s', 's.Products_Departments_Id', '=', 'd.id')
            ->leftJoin('Products_Sub_Sub_Department_T as l', 'l.Product_Sub_Department_Id', '=', 's.id')
            ->select([
                'd.id as department_id',
                'd.Source_Main_Id as source_main_id',
                'd.Source_Main_Sequence as source_main_sequence',
                'd.Product_Department_Name as department_name',
                'd.Product_Department_Code as department_code',
                's.id as sub_department_id',
                's.Sub_Department_Name as sub_department_name',
                's.Products_Sub_Department_Code as sub_department_code',
                'l.Product_Sub_Sub_Department_Name as sub_sub_department_name',
                'l.Product_Sub_Sub_Department_Code as sub_sub_department_code',
            ])
            ->orderByRaw('COALESCE(d.Source_Main_Sequence, 2147483647)')
            ->orderBy('d.Product_Department_Name')
            ->orderByRaw('COALESCE(s.Source_Sub_Sequence, 2147483647)')
            ->orderBy('s.Sub_Department_Name')
            ->orderByRaw('COALESCE(l.Source_Sub_Sub_Sequence, 2147483647)')
            ->orderBy('l.Product_Sub_Sub_Department_Name')
            ->chunk(1000, function ($items) use (&$rows, &$previousDepartmentId, &$previousSubDepartmentId): void {
                foreach ($items as $item) {
                    $departmentId = (int) $item->department_id;
                    $subDepartmentId = $item->sub_department_id === null ? null : (int) $item->sub_department_id;
                    $showDepartment = $departmentId !== $previousDepartmentId;
                    $showSubDepartment = $subDepartmentId !== null && ($showDepartment || $subDepartmentId !== $previousSubDepartmentId);

                    $rows[] = $this->hierarchyRow($item, $showDepartment, $showSubDepartment);

                    $previousDepartmentId = $departmentId;
                    $previousSubDepartmentId = $subDepartmentId;
                }
            });

        return $rows;
    }

    private function sourceMainId(mixed $sourceMainId, mixed $sequence): string
    {
        $sourceMainId = trim((string) $sourceMainId);
        if ($sourceMainId !== '') {
            return $sourceMainId;
        }

        $sequence = (int) $sequence;

        return $sequence > 0 ? 'MAIN-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT) : '';
    }

    private function hierarchyRow(object $item, bool $showDepartment, bool $showSubDepartment): array
    {
        return [
            $showDepartment ? (string) ($item->source_main_sequence ?? '') : '',
            $showDepartment ? $this->sourceMainId($item->source_main_id, $item->source_main_sequence) : '',
            $showDepartment ? (string) ($item->department_name ?? '') : '',
            $showDepartment ? (string) ($item->department_code ?? '') : '',
            $showSubDepartment ? (string) ($item->sub_department_name ?? '') : '',
            $showSubDepartment ? (string) ($item->sub_department_code ?? '') : '',
            (string) ($item->sub_sub_department_name ?? ''),
            (string) ($item->sub_sub_department_code ?? ''),
        ];
    }

    /** @param array<int, array<int, string>> $rows */
    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'
            .'<col min="1" max="1" width="8" customWidth="1"/>'
            .'<col min="2" max="2" width="16" customWidth="1"/>'
            .'<col min="3" max="3" width="34" customWidth="1"/>'
            .'<col min="4" max="4" width="38" customWidth="1"/>'
            .'<col min="5" max="5" width="36" customWidth="1"/>'
            .'<col min="6" max="6" width="46" customWidth="1"/>'
            .'<col min="7" max="7" width="40" customWidth="1"/>'
            .'<col min="8" max="8" width="60" customWidth="1"/>'
            .'</cols><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="'.$excelRow.'">';
            foreach ($row as $columnIndex => $value) {
                $cell = $this->columnName($columnIndex + 1).$excelRow;
                $style = $excelRow === 1 ? ' s="1"' : '';
                $xml .= '<c r="'.$cell.'" t="inlineStr"'.$style.'><is><t>'.$this->escapeXml($value).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData><autoFilter ref="A1:H'.max(1, count($rows)).'"/></worksheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Product Hierarchy" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';
    }
}
