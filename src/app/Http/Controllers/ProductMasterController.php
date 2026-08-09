<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductImages;
use App\Models\ProductMaster;
use App\Helpers\CodeGenerator;
use Sentry\State\HubInterface;
use App\Models\ProductsBarcodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\ProductBulkPrice;
use App\Models\ProductSpecificationProduct;
use App\Support\Pricing\BulkPriceRules;

class ProductMasterController extends Controller
{
    //


    public function index(Request $request)
    {


        $search   = trim((string) $request->query('search', ''));
        $sortBy   = $request->query('sortBy', $request->query('sort_by', 'id'));
        $sortDir  = strtolower((string) $request->query('sortDir', $request->query('sort_dir', 'desc')));
        $perPage  = (int) $request->query('per_page', 10);
        $owner    = strtolower((string) $request->query('owner', $request->query('source', 'all')));

        $departmentId = $request->query('product_department_id', $request->query('department_id'));
        $subDepartmentId = $request->query('product_sub_department_id', $request->query('sub_department_id'));
        $subSubDepartmentId = $request->query('product_sub_sub_department_id', $request->query('sub_sub_department_id'));
        $vendorId = $request->query('vendor_id');

        $query = ProductMaster::query()
            ->with([
                'department:id,Product_Department_Name,Product_Department_Name_Ar',
                'subDepartment:id,Sub_Department_Name,Sub_Department_Name_Ar',
                'subSubDepartment:id,Product_Sub_Sub_Department_Name,Product_Sub_Sub_Department_Name_Ar',
                'vendor:id,Vendor_Code,Vendor_Name,Trade_Name',
            ]);

        // Trashed view: ?trashed=1 or ?status=deleted -> soft-deleted products only.
        // (Default queries auto-exclude trashed rows via the SoftDeletes trait.)
        $trashed = strtolower((string) $request->query('trashed', ''));
        $statusFilter = strtolower((string) $request->query('status', ''));

        if (in_array($trashed, ['1', 'true'], true) || $statusFilter === 'deleted') {
            $query->onlyTrashed();
        }

        if ($owner === 'company') {
            $query->whereNull('Vendor_Id');
        } elseif ($owner === 'vendor') {
            $query->whereNotNull('Vendor_Id');
        }

        if ($departmentId !== null && $departmentId !== '') {
            $query->where('Product_Department_Id', $departmentId);
        }

        if ($subDepartmentId !== null && $subDepartmentId !== '') {
            $query->where('Product_Sub_Department_Id', $subDepartmentId);
        }

        if ($subSubDepartmentId !== null && $subSubDepartmentId !== '') {
            $query->where('Product_Sub_Sub_Department_Id', $subSubDepartmentId);
        }

        if ($vendorId !== null && $vendorId !== '') {
            $query->where('Vendor_Id', $vendorId);
        }

        //search by name
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('Product_Name', 'like', "%{$search}%")
                    ->orWhere('Product_Code', 'like', "%{$search}%")
                    ->orWhere('Product_Sku', 'like', "%{$search}%");
            });
        }

        // whitelist sortable columns
        if (!in_array($sortBy, ['id', 'Product_Name', 'Product_Price', 'Product_Stock', 'Status', 'Vendor_Id', 'created_at'])) {
            $sortBy = 'id';
        }

        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $products = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json($products);
    }


    public function getLatestProducts()
    {
        return response()->json(ProductMaster::latest('id')->value('id'));
    }

    /**
     * 422 message used everywhere the minimum-selling-price floor is enforced.
     */
    public static function priceFloorMessage($floor): string
    {
        return sprintf(
            'Price cannot be below the minimum selling price (%s).',
            number_format((float) $floor, 3, '.', '')
        );
    }

    public function store(Request $request)
    {
        // Price floor: minimum selling price is stated first, then the price
        // must not go below it (Product_Price >= Minimum_Selling_Price).
        $request->validate([
            'minimum_selling_price' => ['required', 'numeric', 'min:0'],
            'price'                 => ['required', 'numeric', 'gte:minimum_selling_price'],
            'cost'                  => ['nullable', 'numeric', 'min:0'],
        ], [
            'price.gte' => self::priceFloorMessage($request->input('minimum_selling_price')),
        ]);

        try {

            $product = null;

            DB::transaction(function () use ($request, &$product) {

                $productMasterCode = CodeGenerator::createCode('PROD', 'Products_Master_T', 'Product_Code');



                $productBrandId = $request->input('product_brand_id');
                $productBrandId = $productBrandId === '' ? null : $productBrandId;

                $product_manufacture_id = $request->input('product_manufacture_id');
                $product_manufacture_id = $product_manufacture_id === '' ? null : $product_manufacture_id;



                // Read raw input
                $rawL = (float) ($request->input('Length_Cm')  ?? 0);
                $rawW = (float) ($request->input('Width_Cm')   ?? 0);
                $rawH = (float) ($request->input('Height_Cm')  ?? 0);

                // Conversion factors to meters
                $unit = strtolower($request->input('volume_type', 'cm'));
                $toMeters = [
                    'mm' => 0.001,     // millimeter → meter
                    'cm' => 0.01,      // centimeter → meter
                    'm'  => 1.0,       // meter → meter
                    'in' => 0.0254,    // inch → meter
                    'ft' => 0.3048,    // foot → meter
                ];

                if (!isset($toMeters[$unit])) {
                    return response()->json(['message' => 'Invalid volume_type'], 422);
                }
                $k = $toMeters[$unit];

                // Normalize to meters
                $L_m = round($rawL * $k, 4); // store up to 4 decimal places
                $W_m = round($rawW * $k, 4);
                $H_m = round($rawH * $k, 4);

                // Calculate CBM directly in meters³
                $cbm = round($L_m * $W_m * $H_m, 3);

                $product = ProductMaster::create([
                    'Product_Code' => $productMasterCode,
                    'Product_Department_Id' => $request->product_department_id,
                    'Product_Sub_Department_Id' => $request->product_sub_department_id,
                    'Product_Sub_Sub_Department_Id' => $request->product_sub_sub_department_id,
                    'Product_Type_Id' => $request->product_type_id,
                    'Product_Brand_Id' => $productBrandId,
                    'Product_Manufacture_Id' => $product_manufacture_id,
                    'Product_Name' => $request->name,
                    'Product_Name_Ar' => $request->name_ar,
                    'Product_Description' => $request->description,
                    'Product_Price' => $request->price,
                    'Product_Cost' => $request->input('cost') === '' ? null : $request->input('cost'),
                    'Minimum_Selling_Price' => $request->input('minimum_selling_price'),
                    'Product_Stock' => $request->stock,
                    'Product_Sku' => $request->product_sku,
                    'volume_type' => $request->volume_type,
                    'Weight_Kg' => $request->Weight_Kg,

                    'Length_Cm'  => $L_m,
                    'Width_Cm'   => $W_m,
                    'Height_Cm'  => $H_m,
                    'Volume_Cbm' => $cbm,
                    'Created_By' => Auth::id(),
                    'Created_Date' => now(),
                ]);


                $inhouseBarcode = $product->id . '-' . $request->input('inhouse_barcode');


                $product->update([
                    'Inhouse_Barcode_Source' => $inhouseBarcode,
                ]);



                $barcodes = json_decode($request->barcodes, true); // true = associative array

                if (is_array($barcodes)) {
                    foreach ($barcodes as $code) {
                        if (!is_string($code) || empty($code)) continue;

                        $productBarCode = CodeGenerator::createCode('PRBAR', 'Product_Supplier_BarCode_T', 'Product_Barcode_Code');

                        ProductsBarcodes::create([
                            'Product_Barcode_Code' => $productBarCode,
                            'Products_Id' => $product->id,
                            'Supplier_Barcode' => $code,
                            'Created_By' => Auth::id(),
                            'Created_Date' => now(),
                        ]);
                    }
                }




                if ($request->hasFile('file')) {
                    foreach ($request->file('file') as $file) {


                        $path = Storage::disk('uploads')->put('Products', $file, 'public');


                        $imagePath = $path;
                        $imageSize = $file->getSize();
                        $imageExtension = $file->getClientOriginalExtension();
                        $imageType = $file->getMimeType();

                        // Example: save to ProductImages model
                        ProductImages::create([
                            'Product_Image_Code' => CodeGenerator::createCode('PIMG', 'Products_Images_T', 'Product_Image_Code'),
                            'Products_Id' => $product->id,
                            'Image_Path' => $imagePath,
                            'Image_Size' => $imageSize,
                            'Image_Extension' => $imageExtension,
                            'Image_Type' => $imageType,
                            'Created_By' => Auth::id(),
                            'Created_Date' => now()
                        ]);
                    }
                }
            });

            return response()->json(['data' => $product], 201);
        } catch (\Exception $e) {

            app(HubInterface::class)->captureException($e);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function show(ProductMaster $productmaster)
    {
        // Quantity-tier bulk prices ride along on the detail payload
        // (ordered by Min_Qty via the relation). Guarded for the deploy
        // window before the bulk-prices migration has run.
        if (Schema::hasTable('Products_Bulk_Prices_T')) {
            $productmaster->load('bulkPrices');
        }

        return response()->json($productmaster);
    }

    /**
     * Replace-set the quantity-tier bulk prices on a product.
     *
     * POST /api/productmaster/{id}/bulk-prices
     * Body: {tiers: [{min_qty, max_qty (nullable = "and above"), unit_price}, ...]}
     * An empty tiers array clears all tiers.
     *
     * Smart validation (BulkPriceRules): min_qty >= 1, max_qty >= min_qty,
     * unit_price > 0, no overlapping ranges, and — when the product has a
     * non-null Minimum_Selling_Price — every tier price >= that floor (422).
     */
    public function setBulkPrices(Request $request, int $id)
    {
        if (! Schema::hasTable('Products_Bulk_Prices_T')) {
            return response()->json([
                'message' => 'Bulk price table is not migrated yet.',
            ], 409);
        }

        $product = ProductMaster::query()->findOrFail($id);

        $data = $request->validate([
            'tiers'   => ['present', 'array'],
            'tiers.*' => ['array'],
        ]);

        $tiers = array_values($data['tiers'] ?? []);

        $floor = $product->Minimum_Selling_Price !== null
            ? (float) $product->Minimum_Selling_Price
            : null;

        $errors = BulkPriceRules::validateSet($tiers, $floor);

        if (! empty($errors)) {
            return response()->json([
                'message' => $errors[0],
                'errors'  => ['tiers' => $errors],
            ], 422);
        }

        DB::transaction(function () use ($product, $tiers) {
            // Replace-set semantics: no soft deletes on tiers.
            ProductBulkPrice::query()->where('Products_Id', $product->id)->delete();

            foreach ($tiers as $tier) {
                // BulkPriceRules accepts both snake_case and house-cased keys,
                // so persist with the same dual-casing fallbacks (mirrors
                // AdminTempProductController::applyBulkPriceChanges).
                $min   = $tier['min_qty'] ?? $tier['Min_Qty'] ?? null;
                $max   = $tier['max_qty'] ?? $tier['Max_Qty'] ?? null;
                $price = $tier['unit_price'] ?? $tier['Unit_Price'] ?? null;

                ProductBulkPrice::create([
                    'Products_Id' => $product->id,
                    'Min_Qty'     => (int) $min,
                    'Max_Qty'     => ($max === null || $max === '') ? null : (int) $max,
                    'Unit_Price'  => round((float) $price, 3),
                    'Created_By'  => Auth::id(),
                ]);
            }
        });

        return response()->json([
            'message' => 'Bulk prices saved.',
            'data'    => $product->bulkPrices()->get(),
        ]);
    }


    public function update(Request $request, ProductMaster $productmaster)
    {
        $request->validate([
            'price'                 => ['nullable', 'numeric'],
            'cost'                  => ['nullable', 'numeric', 'min:0'],
            'minimum_selling_price' => ['nullable', 'numeric', 'min:0'],
            'Product_Price'         => ['nullable', 'numeric'],
            'Product_Cost'          => ['nullable', 'numeric', 'min:0'],
            'Minimum_Selling_Price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Is_Active is stripped too: activation is owned by the dedicated
        // activate/deactivate endpoints, and the edit page PUTs the whole
        // hydrated row back — a stale Is_Active must not flip the state.
        // (lowercase variant included: MSSQL columns are case-insensitive)
        // bulk_prices is stripped for the same reason: show() attaches the
        // bulkPrices relation (serialized as bulk_prices), the edit page PUTs
        // the whole hydrated row back, and tiers are owned by the dedicated
        // POST /productmaster/{id}/bulk-prices endpoint — letting the array
        // through makes Eloquent try UPDATE ... SET [bulk_prices] = Array.
        $data = $request->except(['id', 'created_at', 'updated_at', 'deleted_at', 'Is_Active', 'is_active', 'bulk_prices', 'bulkPrices', 'Bulk_Prices']);

        // Accept the admin-UI friendly keys and map them to real columns.
        $aliases = [
            'price'                 => 'Product_Price',
            'cost'                  => 'Product_Cost',
            'minimum_selling_price' => 'Minimum_Selling_Price',
        ];

        foreach ($aliases as $alias => $column) {
            if (array_key_exists($alias, $data)) {
                $data[$column] = $data[$alias] === '' ? null : $data[$alias];
                unset($data[$alias]);
            }
        }

        // Price floor on the RESULTING pair: any value absent from the payload
        // falls back to the current DB value.
        $resultingPrice = array_key_exists('Product_Price', $data)
            ? $data['Product_Price']
            : $productmaster->Product_Price;
        $resultingFloor = array_key_exists('Minimum_Selling_Price', $data)
            ? $data['Minimum_Selling_Price']
            : $productmaster->Minimum_Selling_Price;

        if ($resultingPrice !== null && $resultingPrice !== ''
            && $resultingFloor !== null && $resultingFloor !== ''
            && (float) $resultingPrice < (float) $resultingFloor) {
            throw ValidationException::withMessages([
                'price' => self::priceFloorMessage($resultingFloor),
            ]);
        }

        if ($request->hasAny(['Length_Cm', 'Width_Cm', 'Height_Cm'])) {
            $data = array_merge($data, $this->normalizeShippingDimensions($request, $productmaster));
        }

        $productmaster->update($data);

        return response()->json($productmaster->fresh());
    }

    public function destroy(ProductMaster $productmaster)
    {
        try {
            // Soft delete ONLY the product row. Images, barcodes and
            // specifications (and the uploaded files) are intentionally kept so
            // restore() brings the product back complete.
            $productmaster->delete();

            return response()->json(['message' => 'Product moved to Deleted. It can be restored.'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Restore a soft-deleted product.
     * Route-model binding excludes trashed rows, so this takes the raw id
     * and resolves it withTrashed() explicitly.
     */
    public function restore(int $id)
    {
        $product = ProductMaster::withTrashed()->findOrFail($id);

        if ($product->trashed()) {
            $product->restore();
        }

        return response()->json([
            'success' => true,
            'message' => 'Product restored successfully.',
            'data'    => $product->fresh(),
        ]);
    }

    public function activate(ProductMaster $productmaster)
    {
        return $this->setActive($productmaster, true);
    }

    public function deactivate(ProductMaster $productmaster)
    {
        return $this->setActive($productmaster, false);
    }

    private function setActive(ProductMaster $productmaster, bool $active)
    {
        $productmaster->update(['Is_Active' => $active ? 1 : 0]);

        return response()->json([
            'success' => true,
            'message' => $active
                ? 'Product activated.'
                : 'Product deactivated. It is now hidden from the storefront.',
            'data'    => $productmaster->fresh(),
        ]);
    }

    private function normalizeShippingDimensions(Request $request, ProductMaster $productmaster): array
    {
        $unit = strtolower((string) $request->input('volume_type', $productmaster->volume_type ?: 'cm'));
        $toMeters = [
            'mm' => 0.001,
            'cm' => 0.01,
            'm'  => 1.0,
            'in' => 0.0254,
            'ft' => 0.3048,
        ];

        if (!array_key_exists($unit, $toMeters)) {
            throw ValidationException::withMessages([
                'volume_type' => 'Invalid dimension unit. Use mm, cm, m, in, or ft.',
            ]);
        }

        $lengthInput = $request->has('Length_Cm')
            ? (float) $request->input('Length_Cm')
            : ((float) $productmaster->Length_Cm / $toMeters[$unit]);
        $widthInput = $request->has('Width_Cm')
            ? (float) $request->input('Width_Cm')
            : ((float) $productmaster->Width_Cm / $toMeters[$unit]);
        $heightInput = $request->has('Height_Cm')
            ? (float) $request->input('Height_Cm')
            : ((float) $productmaster->Height_Cm / $toMeters[$unit]);

        $length = round($lengthInput * $toMeters[$unit], 4);
        $width = round($widthInput * $toMeters[$unit], 4);
        $height = round($heightInput * $toMeters[$unit], 4);

        return [
            'volume_type' => $unit,
            'Length_Cm' => $length,
            'Width_Cm' => $width,
            'Height_Cm' => $height,
            'Volume_Cbm' => round($length * $width * $height, 6),
        ];
    }
}
