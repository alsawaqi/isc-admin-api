<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductImages;
use App\Models\ProductMaster;
use App\Helpers\CodeGenerator;
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
            ProductMaster::select('id','name','price','created_at')->orderBy('id', 'DESC')->get()
        );
    }

    public function store(Request $request)
    {

        try{

        
        DB::transaction(function () use ($request) {
            //
       
        
        $productMasterCode = CodeGenerator::createCode('PROD', 'Products_Master_T', 'Product_Code');

        $product = ProductMaster::create([
                                'Product_Code' => $productMasterCode,
                                'product_department_id' => $request->product_department_id,
                                'product_sub_department_id' => $request->product_sub_department_id,
                                'product_sub_sub_department_id' => $request->product_sub_sub_department_id,
                                'product_type_id' => $request->product_type_id,
                                'product_brand_id' => $request->product_brand_id,
                                'product_manufacture_id' => $request->product_manufacture_id,
                                'name' => $request->name,
                                'name_ar' => $request->name_ar,
                                'description' => $request->description,
                                'price' => $request->price,
                                'stock' => $request->stock,
                                'inhouse_barcode_source' => $request->inhouse_barcode,
                                'Created_By' => Auth::id() 
                       ]);



           if ($request->has('barcodes') && is_array($request->barcodes)) {
                    foreach ($request->barcodes as $code) {
                        if (!is_string($code) || empty($code)) continue;

                            $productBarCode = CodeGenerator::createCode('PRBAR', 'Products_Barcodes_T', 'product_barcode_code');
                                ProductsBarcodes::create([
                                                    'product_barcode_code' => $productBarCode,
                                                    'product_id' => $product->id,
                                                    'barcode' => $code
                                                ]);
                            }
                        }



            if ($request->has('specifications') && is_array($request->specifications)) {
                foreach ($request->specifications as $spec) {
                  
              ProductSpecificationProduct::create([
                            'product_id' => $product->id,
                            'product_specification_description_id' => $spec['product_specification_description_id'],
                            'value' => $spec['value']
                        ]);
             
            }
        }



        if ($request->hasFile('file')) {
            foreach ($request->file('file') as $file) {


                $path = Storage::disk('r2')->put('Products', $file, 'public');

                // Optional: save to DB or return each image info
                $imagePath = $path;
                $imageSize = $file->getSize();
                $imageExtension = $file->getClientOriginalExtension();
                $imageType = $file->getMimeType();

                // Example: save to ProductImages model
                ProductImages::create([
                    'product_image_code' => CodeGenerator::createCode('PIMG', 'Products_Images_T', 'product_image_code'),
                    'product_id' => $product->id,
                    'image_path' => $imagePath,
                    'size' => $imageSize,
                    'extension' => $imageExtension,
                    'type' => $imageType,
                    'Created_By' => Auth::id()
                ]);
            }
     }



         
        });
       return response()->json(['message' => $request->specifications], 201);

        }catch(\Exception $e){
            return response()->json(['error' => $e], 500);
        }
    }
}
