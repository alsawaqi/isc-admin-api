<?php

namespace App\Services;

use RuntimeException;

class ProductImportWorkbook
{
    public const HEADERS = ['srno', 'maingroups', 'subgroup', 'subsubgroup', 'brandname', 'productname', 'productdescription', 'barcode', 'mainstore', 'cost', 'minsalesrate', 'maxsalesrate'];

    public function parse(string $path): array
    {
        $table = app(ProductHierarchyXlsxParser::class)->readTable($path, self::HEADERS);
        $rows = [];
        foreach ($table['rows'] as $raw) {
            if ($raw['row'] <= $table['header_row']) {
                continue;
            }
            $values = [];
            $formulas = [];
            foreach (self::HEADERS as $header) {
                $column = $table['columns'][$header];
                $values[$header] = trim($raw['cells'][$column] ?? '');
                if (isset($raw['formulas'][$column])) {
                    $formulas[] = $header;
                }
            }
            if (array_filter($values, fn ($value) => $value !== '') === [] && $formulas === []) {
                continue;
            }
            $rows[] = ['row' => $raw['row'], 'values' => $values, 'formulas' => $formulas];
            if (count($rows) > 2000) {
                throw new RuntimeException('Import at most 2,000 products per workbook.');
            }
        }
        if ($rows === []) {
            throw new RuntimeException('The workbook contains no product rows.');
        }

        return ['sheet' => $table['sheet'], 'rows' => $rows];
    }

    public static function key(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    public static function categoryKey(array $values): string
    {
        return hash('sha256', implode('|', array_map(self::key(...), [$values['maingroups'], $values['subgroup'], $values['subsubgroup']])));
    }
}
