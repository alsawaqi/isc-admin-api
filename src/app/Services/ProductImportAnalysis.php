<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProductImportAnalysis
{
    public function lookups(): array
    {
        return [
            'types' => DB::table('Products_Types_Master_T')->orderBy('Product_Types_Name')->get(['id', 'Product_Types_Name as name'])->all(),
            'brands' => DB::table('Products_Brands_Master_T')->orderBy('Products_Brands_Name')->get(['id', 'Products_Brands_Name as name'])->all(),
        ];
    }

    public function analyze(array $workbook, array $options): array
    {
        $lookups = $this->lookups();
        $brands = collect($lookups['brands'])->keyBy('id');
        $brandNames = collect($lookups['brands'])->groupBy(fn ($b) => ProductImportWorkbook::key($b->name));
        $categories = DB::table('Products_Sub_Sub_Department_T as l')
            ->join('Products_Sub_Department_T as s', 's.id', '=', 'l.Product_Sub_Department_Id')
            ->join('Products_Departments_T as d', 'd.id', '=', 's.Products_Departments_Id')
            ->get(['l.id', 's.id as sub_id', 'd.id as department_id', 'd.Product_Department_Name as main', 's.Sub_Department_Name as sub', 'l.Product_Sub_Sub_Department_Name as leaf'])
            ->keyBy('id');
        $paths = $categories->groupBy(fn ($c) => ProductImportWorkbook::categoryKey(['maingroups' => $c->main, 'subgroup' => $c->sub, 'subsubgroup' => $c->leaf]));
        $inhouse = $workbook['inhouse_barcodes'] ?? false;
        $codes = array_column(array_column($workbook['rows'], 'values'), 'barcode');
        $barcodes = $this->existingKeys('Product_Supplier_BarCode_T', 'Supplier_Barcode', $codes);
        if ($inhouse) {
            $barcodes += $this->existingKeys('Products_Master_T', 'Inhouse_Barcode_Source', $codes);
        }
        $skus = $this->existingKeys('Products_Master_T', 'Product_Sku', array_column(array_column($workbook['rows'], 'values'), 'srno'));
        $issues = [];
        $typeId = (int) ($options['product_type_id'] ?? 0);
        if (! collect($lookups['types'])->contains(fn ($t) => (int) $t->id === $typeId)) {
            $issues[] = $this->issue(0, 'error', 'product_type', 'Choose a product type for this import.');
        }
        $categoryGroups = $brandGroups = $seenBarcode = $seenSku = $rows = [];
        foreach ($workbook['rows'] as $raw) {
            $v = $raw['values'];
            $row = $raw['row'];
            $rowIssues = [];
            $add = function ($severity, $field, $message) use (&$rowIssues, $row) {
                $rowIssues[] = $this->issue($row, $severity, $field, $message);
            };
            foreach (['srno', 'maingroups', 'subgroup', 'subsubgroup', 'brandname', 'productname', 'productdescription', 'barcode'] as $field) {
                if ($v[$field] === '') {
                    $add('error', $field, "{$field} is required.");
                }
                if (mb_strlen($v[$field]) > ($field === 'productdescription' ? 10000 : 255)) {
                    $add('error', $field, "{$field} is too long.");
                }
            }
            if ($inhouse && strlen(mb_convert_encoding($v['barcode'], 'UTF-16LE', 'UTF-8')) > 100) {
                $add('error', 'barcode', 'The in-house barcode exceeds the 50-character database limit.');
            }
            foreach ($raw['formulas'] as $field) {
                $add('error', $field, 'Replace this formula with a fixed value before importing.');
            }
            $catKey = ProductImportWorkbook::categoryKey($v);
            $mappedCategory = $options['category_map'][$catKey] ?? null;
            $matches = $paths->get($catKey, collect());
            $category = $mappedCategory ? $categories->get((int) $mappedCategory) : ($matches->count() === 1 ? $matches->first() : null);
            if (! $category) {
                $add('error', 'category', 'Match this category path to an existing category.');
            }
            if (! isset($categoryGroups[$catKey])) {
                $candidates = $categories->filter(fn ($c) => ProductImportWorkbook::key($c->leaf) === ProductImportWorkbook::key($v['subsubgroup']))->values();
                if ($category && ! $candidates->contains('id', $category->id)) {
                    $candidates->prepend($category);
                }
                $categoryGroups[$catKey] = ['key' => $catKey, 'source' => implode(' / ', [$v['maingroups'], $v['subgroup'], $v['subsubgroup']]), 'selected_id' => $category?->id,
                    'options' => $candidates->take(50)->map(fn ($c) => ['id' => $c->id, 'name' => "{$c->main} / {$c->sub} / {$c->leaf}"])->all()];
            }
            $brandKey = hash('sha256', ProductImportWorkbook::key($v['brandname']));
            $mappedBrand = $options['brand_map'][$brandKey] ?? null;
            $brandMatches = $brandNames->get(ProductImportWorkbook::key($v['brandname']), collect());
            $brand = $mappedBrand && $mappedBrand !== 'new' ? $brands->get((int) $mappedBrand) : ($brandMatches->count() === 1 ? $brandMatches->first() : null);
            $createBrand = ! $brand && $mappedBrand === 'new' && $brandMatches->isEmpty() && $v['brandname'] !== '';
            if (! $brand && ! $createBrand) {
                $add('error', 'brand', 'Choose an existing brand or explicitly create this brand.');
            }
            $brandGroups[$brandKey] = ['key' => $brandKey, 'source' => $v['brandname'], 'selected_id' => $brand?->id ?? ($createBrand ? 'new' : null)];
            foreach (['barcode' => &$seenBarcode, 'srno' => &$seenSku] as $field => &$seen) {
                $key = ProductImportWorkbook::key($v[$field]);
                if ($key !== '' && isset($seen[$key])) {
                    $add('error', $field, "Duplicate {$field}; also used on Excel row {$seen[$key]}.");
                }
                $seen[$key] = $row;
            }
            unset($seen);
            if (isset($barcodes[ProductImportWorkbook::key($v['barcode'])])) {
                $add('error', 'barcode', 'This barcode already exists. Existing products are never overwritten.');
            }
            if (isset($skus[ProductImportWorkbook::key($v['srno'])])) {
                $add('error', 'srno', 'This SKU / Sr No already exists. Existing products are never overwritten.');
            }
            $stock = null;
            if ($v['mainstore'] === '' && ($options['blank_stock'] ?? 'reject') === 'zero') {
                $stock = 0;
                $add('warning', 'mainstore', 'Blank stock explicitly treated as zero.');
            } elseif (! preg_match('/^\d+$/D', $v['mainstore']) || (float) $v['mainstore'] > 2147483647) {
                $add('error', 'mainstore', 'Enter a whole stock quantity of zero or more.');
            } else {
                $stock = (int) $v['mainstore'];
            }
            $priceColumn = ($options['price_column'] ?? 'max') === 'min' ? 'minsalesrate' : 'maxsalesrate';
            $price = $this->money($v[$priceColumn], 3);
            $minimum = $this->money($v['minsalesrate'], 3);
            $cost = $v['cost'] === '' ? null : $this->money($v['cost'], 3);
            if ($price === null) {
                $add('error', $priceColumn, 'Enter a valid selling price with at most 3 decimal places.');
            }
            if ($minimum === null) {
                $add('error', 'minsalesrate', 'Enter a valid minimum selling price.');
            }
            if ($price !== null && $minimum !== null && (float) $price < (float) $minimum) {
                $add('error', $priceColumn, 'Selling price cannot be below the minimum selling price.');
            }
            if ($v['cost'] !== '' && $cost === null) {
                $add('error', 'cost', 'Enter a valid cost, or leave it blank.');
            }
            if ($v['cost'] === '') {
                $add('warning', 'cost', 'Cost is missing and will remain empty.');
            }
            $rows[] = ['row' => $row, 'sku' => $v['srno'], 'name' => $v['productname'], 'description' => $v['productdescription'], 'barcode' => $v['barcode'],
                'stock' => $stock, 'price' => $price, 'minimum' => $minimum, 'cost' => $cost, 'type_id' => $typeId,
                'category' => $category ? (array) $category : null, 'brand_id' => $brand?->id, 'new_brand' => $createBrand ? $v['brandname'] : null,
                'brand' => $brand?->name ?? $v['brandname'], 'issues' => $rowIssues, 'valid' => ! collect($rowIssues)->contains('severity', 'error')];
            array_push($issues, ...$rowIssues);
        }
        $errors = collect($issues)->where('severity', 'error')->count();

        return ['sheet' => $workbook['sheet'], 'rows' => $rows, 'issues' => $issues, 'categories' => array_values($categoryGroups), 'brands' => array_values($brandGroups),
            'can_commit' => $errors === 0, 'summary' => ['total' => count($rows), 'valid' => collect($rows)->where('valid', true)->count(), 'errors' => $errors,
                'warnings' => collect($issues)->where('severity', 'warning')->count(), 'new_brands' => count(array_unique(array_filter(array_column($rows, 'new_brand'))))]];
    }

    public function digest(array $analysis): string
    {
        return hash('sha256', json_encode($analysis['rows'], JSON_THROW_ON_ERROR));
    }

    private function money(string $value, int $places): ?string
    {
        if (! preg_match('/^\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/D', $value)) {
            return null;
        }
        $number = (float) $value;
        if (! is_finite($number) || $number > 99999999.99) {
            return null;
        }
        $rounded = round($number, $places);
        // Excel stores binary floating-point tails, e.g. 45.948999999999998.
        // Accept representation noise, but never silently round meaningful money.
        if (abs($number - $rounded) > max(1e-10, abs($number) * 1e-14)) {
            return null;
        }

        return number_format($rounded, $places, '.', '');
    }

    private function existingKeys(string $table, string $column, array $values): array
    {
        $keys = [];
        foreach (array_chunk(array_unique(array_filter(array_map(ProductImportWorkbook::key(...), $values), fn ($value) => $value !== '')), 500) as $chunk) {
            foreach (DB::table($table)->whereIn(DB::raw("LOWER(LTRIM(RTRIM([$column])))"), $chunk)->pluck($column) as $value) {
                $keys[ProductImportWorkbook::key($value)] = true;
            }
        }

        return $keys;
    }

    private function issue(int $row, string $severity, string $field, string $message): array
    {
        return compact('row', 'severity', 'field', 'message');
    }
}
