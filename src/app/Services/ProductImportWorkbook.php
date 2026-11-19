<?php

namespace App\Services;

use RuntimeException;

class ProductImportWorkbook
{
    public const HEADERS = ['srno', 'maingroups', 'subgroup', 'subsubgroup', 'brandname', 'productname', 'productdescription', 'barcode', 'mainstore', 'cost', 'minsalesrate', 'maxsalesrate'];

    public const TEMPLATE_HEADERS = ['sku', 'category', 'subcategory', 'subsubcategory', 'brand', 'productname', 'description', 'inhousebarcode', 'stockquantity', 'costomr', 'minimumsellingpriceomr', 'websitesellingpriceomr'];

    public function parse(string $path): array
    {
        $table = app(ProductHierarchyXlsxParser::class)->readTable($path, self::HEADERS, self::TEMPLATE_HEADERS);
        $inhouse = array_diff(self::TEMPLATE_HEADERS, array_keys($table['columns'])) === [];
        if (! $inhouse && isset($table['columns']['inhousebarcode'])) {
            throw new RuntimeException('Use the product upload template to import an in-house barcode.');
        }
        $headers = $inhouse ? self::TEMPLATE_HEADERS : self::HEADERS;
        $rows = [];
        foreach ($table['rows'] as $raw) {
            if ($raw['row'] <= $table['header_row']) {
                continue;
            }
            $values = [];
            $formulas = [];
            foreach (self::HEADERS as $index => $header) {
                $column = $table['columns'][$headers[$index]];
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

        return ['sheet' => $table['sheet'], 'rows' => $rows, 'inhouse_barcodes' => $inhouse];
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
