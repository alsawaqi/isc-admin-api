<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductImages;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProductImagesController extends Controller
{
 
    public function getImages($productId)
{
    $images = ProductImages::where('Products_Id', $productId)
                ->select('Image_Path', 'id', 'Product_Image_Code')
                ->get();

    return response()->json($images);
}

    public function uploadImages(Request $request, $productId)
{
    if ($request->hasFile('file')) {
        foreach ($request->file('file') as $file) {
            $path = Storage::disk('r2')->put('Products', $file, 'public');

            $imagePath = $path;
            $imageSize = $file->getSize();
            $imageExtension = $file->getClientOriginalExtension();
            $imageType = $file->getMimeType();

            ProductImages::create([
                'Product_Image_Code' => CodeGenerator::createCode('PIMG', 'Products_Images_T', 'Product_Image_Code'),
                'Products_Id' => $productId,
                'Image_Path' => $imagePath,
                'Image_Size' => $imageSize,
                'Image_Extension' => $imageExtension,
                'Image_Type' => $imageType,
                'Created_By' => Auth::id(),
                'Created_Date' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Images uploaded successfully'
        ]);
    }

    return response()->json([
        'success' => false,
        'message' => 'No files provided'
    ], 400);
}
}
