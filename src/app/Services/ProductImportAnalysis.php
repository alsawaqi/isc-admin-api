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

    public function analyze(array $workbook, array $options, bool $lock = false): array
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
        $skuValues = array_column(array_column($workbook['rows'], 'values'), 'srno');
        $supplierCodes = $this->matches('Product_Supplier_BarCode_T', 'Supplier_Barcode', $codes, ['Products_Id'], $lock);
        $skus = $this->matches('Products_Master_T', 'Product_Sku', $skuValues, ['id'], $lock);
        $products = $inhouse ? $this->matches('Products_Master_T', 'Inhouse_Barcode_Source', $codes, ['*'], $lock) : [];
        $codeCounts = array_count_values(array_map(self::identifier(...), $codes));
        $skuCounts = array_count_values(array_map(self::identifier(...), $skuValues));
        $issues = [];
        $typeId = (int) ($options['product_type_id'] ?? 0);
        if (! collect($lookups['types'])->contains(fn ($t) => (int) $t->id === $typeId)) {
            $issues[] = $this->issue(0, 'error', 'product_type', 'Choose a product type for this import.');
        }
        $categoryGroups = $brandGroups = $rows = [];
        foreach ($workbook['rows'] as $raw) {
            $v = $raw['values'];
            $row = $raw['row'];
            $code = self::identifier($v['barcode']);
            $sku = self::identifier($v['srno']);
            $matchesByCode = $products[$code] ?? [];
            $target = count($matchesByCode) === 1 ? $matchesByCode[0] : null;
            $action = $target ? ($target->deleted_at === null ? 'update' : 'restore') : 'add';
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
            if (! $target && ! $category) {
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
            $createBrand = ! $target && ! $brand && $mappedBrand === 'new' && $brandMatches->isEmpty() && $v['brandname'] !== '';
            if (! $target && ! $brand && ! $createBrand) {
                $add('error', 'brand', 'Choose an existing brand or explicitly create this brand.');
            }
            $brandGroups[$brandKey] = ['key' => $brandKey, 'source' => $v['brandname'], 'selected_id' => $brand?->id ?? ($createBrand ? 'new' : null)];
            if ($codeCounts[$code] > 1 || $skuCounts[$sku] > 1) {
                $add('error', 'identity', 'Duplicate SKU or barcode within this workbook.');
            }
            if ($target && empty($options['match_inhouse'])) {
                $add('error', 'barcode', 'This barcode already exists. Reload the import page to preview an update.');
            }
            if (count($matchesByCode) > 1) {
                $add('error', 'barcode', 'This in-house barcode matches multiple products.');
            }
            if ($target && ($target->Vendor_Id !== null || self::identifier((string) $target->Product_Sku) !== $sku)) {
                $add('error', 'srno', 'This barcode belongs to another SKU or a vendor product.');
            }
            foreach ($supplierCodes[$code] ?? [] as $supplier) {
                if (! $target || (int) $supplier->Products_Id !== (int) $target->id) {
                    $add('error', 'barcode', 'This supplier barcode belongs to an existing product.');
                    break;
                }
            }
            foreach ($skus[$sku] ?? [] as $owner) {
                if (! $target || (int) $target->id !== (int) $owner->id) {
                    $add('error', 'srno', 'This SKU belongs to a different product.');
                    break;
                }
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
            $rows[] = ['action' => $action, 'target_id' => $target?->id,
                'target_version' => $target ? hash('sha256', json_encode($target, JSON_THROW_ON_ERROR)) : null,
                'row' => $row, 'sku' => $v['srno'], 'name' => $v['productname'], 'description' => $v['productdescription'], 'barcode' => $v['barcode'],
                'stock' => $stock, 'price' => $price, 'minimum' => $minimum, 'cost' => $cost, 'type_id' => $typeId,
                'category' => $category ? (array) $category : null, 'brand_id' => $brand?->id, 'new_brand' => $createBrand ? $v['brandname'] : null,
                'brand' => $brand?->name ?? $v['brandname'], 'issues' => $rowIssues, 'valid' => ! collect($rowIssues)->contains('severity', 'error')];
            array_push($issues, ...$rowIssues);
        }
        $errors = collect($issues)->where('severity', 'error')->count();

        $ready = collect($rows)->where('valid', true);
        return ['sheet' => $workbook['sheet'], 'rows' => $rows, 'issues' => $issues, 'categories' => array_values($categoryGroups), 'brands' => array_values($brandGroups),
            'can_commit' => $errors === 0, 'summary' => ['total' => count($rows), 'valid' => collect($rows)->where('valid', true)->count(), 'errors' => $errors,
                'add' => $ready->where('action', 'add')->count(), 'update' => $ready->where('action', 'update')->count(),
                'restore' => $ready->where('action', 'restore')->count(), 'blocked' => count($rows) - $ready->count(),
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

    private static function identifier(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function matches(string $table, string $column, array $values, array $fields, bool $lock): array
    {
        $matches = [];
        $keys = array_unique(array_map(self::identifier(...), $values));
        foreach (array_chunk($keys, 500) as $chunk) {
            $query = DB::table($table)->whereIn(DB::raw("LOWER(LTRIM(RTRIM([$column])))"), $chunk);
            if ($lock) {
                $query->lockForUpdate();
            }
            foreach ($query->get($fields === ['*'] ? $fields : [...$fields, $column]) as $record) {
                $matches[self::identifier((string) $record->$column)][] = $record;
            }
        }

        return $matches;
    }

    private function issue(int $row, string $severity, string $field, string $message): array
    {
        return compact('row', 'severity', 'field', 'message');
    }
}
