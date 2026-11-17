<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductImportUndo
{
    private const CHILDREN = ['Product_Supplier_BarCode_T', 'Product_Stock_Movements_T'];

    public function capture(array $productIds, array $brandIds): array
    {
        $snapshot = ['products' => $this->rows('Products_Master_T', 'id', $productIds), 'brands' => $this->rows('Products_Brands_Master_T', 'id', $brandIds), 'children' => []];
        foreach (self::CHILDREN as $table) {
            $snapshot['children'][$table] = $this->rows($table, 'Products_Id', $productIds);
        }

        return $snapshot;
    }

    // Called inside the same transaction and application lock as commit.
    public function remove(array $snapshot, object $actor): array
    {
        $ids = array_column($snapshot['products'], 'id');
        if (! $ids || $this->rows('Products_Master_T', 'id', $ids, true) !== $snapshot['products']) {
            throw new RuntimeException('Undo blocked: an imported product was changed or removed. No products were removed.', 409);
        }
        foreach ($snapshot['products'] as $product) {
            if ((int) $product['Created_By'] !== (int) $actor->id || (bool) $product['Is_Active']) {
                throw new RuntimeException('Undo blocked: only your unchanged, inactive imported products can be removed.', 409);
            }
        }
        foreach (self::CHILDREN as $table) {
            if ($this->rows($table, 'Products_Id', $ids, true) !== $snapshot['children'][$table]) {
                throw new RuntimeException('Undo blocked: barcode or stock records changed after import. No products were removed.', 409);
            }
        }
        $references = $this->references('Products_Master_T', ['Products_Id', 'Product_Id', 'Approved_Product_Id', 'Master_Product_Id', 'Product_Master_Id', 'Products_Master_Id']);
        foreach ($references as [$table, $column]) {
            if (in_array($table, self::CHILDREN, true) && $column === 'Products_Id') {
                continue;
            }
            if ($this->referenced($table, $column, $ids)) {
                throw new RuntimeException('Undo blocked: products are linked to orders, catalogue details, or other records. No products were removed.', 409);
            }
        }
        foreach (self::CHILDREN as $table) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table($table)->whereIn('Products_Id', $chunk)->delete();
            }
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('Products_Master_T')->whereIn('id', $chunk)->delete();
        }

        $removedBrands = 0;
        if ($actor->can('product brands') && $snapshot['brands']) {
            $brandReferences = $this->references('Products_Brands_Master_T', ['Product_Brand_Id', 'Brand_Id', 'Products_Brands_Id', 'Product_Brands_Id']);
            foreach ($snapshot['brands'] as $brand) {
                if ($this->rows('Products_Brands_Master_T', 'id', [$brand['id']], true) !== [$brand]) {
                    continue;
                }
                $used = false;
                foreach ($brandReferences as [$table, $column]) {
                    if ($this->referenced($table, $column, [$brand['id']])) {
                        $used = true;
                        break;
                    }
                }
                if (! $used) {
                    $removedBrands += DB::table('Products_Brands_Master_T')->where('id', $brand['id'])->delete();
                }
            }
        }

        return ['products_removed' => count($ids), 'brands_removed' => $removedBrands, 'brands_retained' => count($snapshot['brands']) - $removedBrands];
    }

    private function rows(string $table, string $column, array $ids, bool $lock = false): array
    {
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk)->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            foreach ($query->get() as $row) {
                $row = (array) $row;
                ksort($row);
                // JSON round-trip gives identical scalar types when read from a job.
                $rows[] = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        usort($rows, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    private function referenced(string $table, string $column, array $ids): bool
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            // SQL Server uses UPDLOCK/HOLDLOCK, including empty key ranges, so
            // new relationships cannot be inserted during the rollback checks.
            if (DB::table($table)->whereIn($column, $chunk)->lockForUpdate()->first([$column])) {
                return true;
            }
        }

        return false;
    }

    private function references(string $target, array $conventionalColumns): array
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            $placeholders = implode(',', array_fill(0, count($conventionalColumns), '?'));
            $rows = DB::select("SELECT DISTINCT SCHEMA_NAME(t.schema_id) AS schema_name, t.name AS table_name, c.name AS column_name
                FROM sys.tables t JOIN sys.columns c ON c.object_id = t.object_id
                WHERE c.name IN ($placeholders) OR EXISTS (
                    SELECT 1 FROM sys.foreign_key_columns fk
                    WHERE fk.parent_object_id = t.object_id AND fk.parent_column_id = c.column_id
                    AND OBJECT_NAME(fk.referenced_object_id) = ?)", [...$conventionalColumns, $target]);

            return array_map(fn ($r) => [$r->schema_name === 'dbo' ? $r->table_name : $r->schema_name.'.'.$r->table_name, $r->column_name], $rows);
        }
        $references = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $columns = array_intersect(Schema::getColumnListing($name), $conventionalColumns);
            foreach (Schema::getForeignKeys($name) as $key) {
                if ($key['foreign_table'] === $target) {
                    $columns = array_merge($columns, $key['columns']);
                }
            }
            foreach (array_unique($columns) as $column) {
                $references[] = [$name, $column];
            }
        }

        return $references;
    }
}
