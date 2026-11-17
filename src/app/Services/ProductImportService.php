<?php

namespace App\Services;

use App\Helpers\CodeGenerator;
use App\Models\ProductBrands;
use App\Models\ProductMaster;
use App\Models\ProductsBarcodes;
use App\Models\ProductStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImportService
{
    public function __construct(private ProductImportAnalysis $analysis) {}

    public function preview(array $workbook, array $options, object $actor, string $name, string $hash): array
    {
        $analysis = $this->analysis->analyze($workbook, $options);
        $token = (string) Str::uuid();
        $expires = now()->addMinutes(30);
        DB::table('Product_Import_Jobs_T')->insert([
            'Token' => $token, 'User_Id' => $actor->id, 'File_Name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255), 'File_Sha256' => $hash,
            'Payload' => json_encode(compact('workbook', 'options'), JSON_THROW_ON_ERROR), 'Plan_Digest' => $this->analysis->digest($analysis),
            'Status' => 'pending', 'Expires_At' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [...$analysis, 'preview_token' => $token, 'expires_at' => $expires->toIso8601String()];
    }

    public function commit(string $token, object $actor): array
    {
        return DB::transaction(function () use ($token, $actor) {
            // Serializes import jobs across API workers; row locks protect replay.
            if (DB::connection()->getDriverName() === 'sqlsrv') {
                $lock = DB::selectOne("DECLARE @r int; EXEC @r = sp_getapplock @Resource=N'product-workbook-import', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result;");
                if ((int) $lock->result < 0) {
                    throw new RuntimeException('Another product import is running. Try again shortly.', 409);
                }
            }
            $job = DB::table('Product_Import_Jobs_T')->where('Token', $token)->lockForUpdate()->first();
            if (! $job) {
                throw new RuntimeException('This preview was not found. Preview the workbook again.', 404);
            }
            if ((int) $job->User_Id !== (int) $actor->id) {
                throw new RuntimeException('This preview belongs to another administrator.', 403);
            }
            if ($job->Status === 'committed') {
                return [...json_decode($job->Result, true, 512, JSON_THROW_ON_ERROR), 'idempotent' => true];
            }
            if (now()->greaterThan($job->Expires_At)) {
                throw new RuntimeException('This preview expired. Preview the workbook again.', 409);
            }
            $payload = json_decode($job->Payload, true, 512, JSON_THROW_ON_ERROR);
            $analysis = $this->analysis->analyze($payload['workbook'], $payload['options']);
            if (! $analysis['can_commit']) {
                throw new RuntimeException('The workbook has errors or the catalogue changed. Preview it again before importing.', 422);
            }
            if (! hash_equals($job->Plan_Digest, $this->analysis->digest($analysis))) {
                throw new RuntimeException('The catalogue changed after preview. Review a fresh preview.', 409);
            }
            if ($analysis['summary']['new_brands'] > 0 && ! $actor->can('product brands')) {
                throw new RuntimeException('Creating brands requires the product brands permission.', 403);
            }
            $ids = [];
            $newBrands = [];
            foreach ($analysis['rows'] as $row) {
                $brandId = $row['brand_id'];
                if ($row['new_brand']) {
                    $brandKey = ProductImportWorkbook::key($row['new_brand']);
                    if (! isset($newBrands[$brandKey])) {
                        $newBrands[$brandKey] = ProductBrands::create([
                            'Product_Brand_Code' => CodeGenerator::createCode('BRND', 'Products_Brands_Master_T', 'Product_Brand_Code'),
                            'Products_Brands_Name' => $row['new_brand'], 'Products_Brands_Name_Ar' => null,
                            'Created_By' => $actor->id, 'Created_Date' => now(),
                        ])->id;
                    }
                    $brandId = $newBrands[$brandKey];
                }
                $product = ProductMaster::create([
                    'Product_Code' => CodeGenerator::createCode('PROD', 'Products_Master_T', 'Product_Code'),
                    'Product_Department_Id' => $row['category']['department_id'], 'Product_Sub_Department_Id' => $row['category']['sub_id'],
                    'Product_Sub_Sub_Department_Id' => $row['category']['id'], 'Product_Type_Id' => $row['type_id'], 'Product_Brand_Id' => $brandId,
                    'Product_Name' => $row['name'], 'Product_Name_Ar' => '', 'Product_Description' => $row['description'],
                    'Product_Sku' => $row['sku'], 'Product_Price' => $row['price'], 'Minimum_Selling_Price' => $row['minimum'], 'Product_Cost' => $row['cost'],
                    'Product_Stock' => $row['stock'], 'Status' => $row['stock'] > 0 ? 'available' : 'out_of_stock',
                    'Is_Active' => false, 'Vendor_Id' => null, 'Created_By' => $actor->id, 'Created_Date' => now(),
                ]);
                $product->update(['Inhouse_Barcode_Source' => $product->id.'-'.random_int(10000, 99999)]);
                ProductsBarcodes::create([
                    'Product_Barcode_Code' => CodeGenerator::createCode('PRBAR', 'Product_Supplier_BarCode_T', 'Product_Barcode_Code'),
                    'Products_Id' => $product->id, 'Supplier_Barcode' => $row['barcode'], 'Created_By' => $actor->id, 'Created_Date' => now(),
                ]);
                ProductStockMovement::create([
                    'Products_Id' => $product->id, 'Vendor_Id' => null, 'Movement_Type' => 'import_initial', 'Quantity_Delta' => $row['stock'],
                    'Quantity' => $row['stock'], 'Previous_Stock' => 0, 'New_Stock' => $row['stock'], 'Actor_Type' => 'admin', 'Actor_Id' => $actor->id,
                    'Actor_Name' => $actor->User_Name ?? $actor->name ?? null, 'Notes' => "Workbook import {$job->id}, Excel row {$row['row']}.",
                ]);
                $ids[] = $product->id;
            }
            $result = ['job_id' => $job->id, 'created' => count($ids), 'product_ids' => $ids, 'brands_created' => count($newBrands), 'active' => false, 'idempotent' => false];
            DB::table('Product_Import_Jobs_T')->where('id', $job->id)->update(['Status' => 'committed', 'Result' => json_encode($result, JSON_THROW_ON_ERROR), 'Committed_At' => now(), 'updated_at' => now()]);

            return $result;
        });
    }
}
