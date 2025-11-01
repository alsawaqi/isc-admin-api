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
use Illuminate\Support\Facades\Storage;
use App\Models\ProductSpecificationProduct;

class ProductMasterController extends Controller
{
    //


    public function index()
    {
        return response()->json(
            ProductMaster::orderBy('id', 'DESC')->get()
        );
    }


    public function getLatestProducts()
    {
        return response()->json(ProductMaster::latest('id')->value('id'));
    }

    public function store(Request $request)
    {

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


                        $path = Storage::disk('r2')->put('Products', $file, 'public');


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


 
     Public function show(ProductMaster $productmaster){
        return response()->json($productmaster);
     }


     public function update(Request $request, ProductMaster $productmaster)
    {

        $product = ProductMaster::where('id', $productmaster->id)->first();

         $product->update($request->all());
       

    }

    public function destroy(ProductMaster $productmaster)
    {
        try {

            DB::transaction(function () use ($productmaster) {
                // Step 1: Get all image paths for this product
                $images = ProductImages::where('product_id', $productmaster->id)->get();

                foreach ($images as $image) {
                    // Step 2: Delete file from R2 bucket
                    if ($image->image_path) {
                        Storage::disk('r2')->delete($image->image_path);
                    }
                }

                // Step 3: Delete image DB records
                ProductImages::where('product_id', $productmaster->id)->delete();

                // Delete barcodes
                ProductsBarcodes::where('product_id', $productmaster->id)->delete();

                // Delete specifications
                ProductSpecificationProduct::where('product_id', $productmaster->id)->delete();

                // Finally, delete the product master record
                $productmaster->delete();
            });

            return response()->json(['message' => 'Product deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
