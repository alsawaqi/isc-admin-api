<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductBrands;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Storage;

class ProductBrandsController extends Controller
{
    public function index()
    {
        return response()->json(
            ProductBrands::orderBy('id', 'DESC')->get()
        );
    }

    public function store(Request $request)
    {

         $imagePath = null;
            $imageSize = null;
            $imageExtension = null;
            $imageType = null;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = Storage::disk('r2')->put('brands', $file, 'public'); // changed to department
                $imagePath = $path;
                $imageSize = $file->getSize();
                $imageExtension = $file->getClientOriginalExtension();
                $imageType = $file->getMimeType();
            }
        $productBrandCode = CodeGenerator::createCode('BRND', 'Products_Brands_Master_T', 'Product_Brand_Code');

        ProductBrands::create([
            'Product_Brand_Code' => $productBrandCode,
            'Products_Brands_Name' => $request->name,
            'Products_Brands_Name_Ar' => $request->name,
            'Products_Brands_Description' => $request->name,
            'Created_By' => $request->user()->id,
            'Created_Date' => now(),
            'Brands_Image_Path' => $imagePath,
            'Brands_Size' => $imageSize,
            'Brands_Extension' => $imageExtension,
            'Brands_Type' => $imageType
        ]);

        return response()->json(['message' => 'Product brand created successfully'], 201);
    }
    //
}
