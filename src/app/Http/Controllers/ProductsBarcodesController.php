<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductsBarcodes;
use Illuminate\Support\Facades\Auth;

class ProductsBarcodesController extends Controller
{
    //

    public function getProductBarcodes($productId)
        {
            $barcodes = ProductsBarcodes::where('Products_Id', $productId)
                        ->pluck('Supplier_Barcode');

            return response()->json($barcodes);
        }

        public function updateProductBarcodes(Request $request, $productId)
            {
                $barcodes = json_decode($request->barcodes, true); // ['123', '456', ...]

                // Clean up existing ones
                ProductsBarcodes::where('Products_Id', $productId)->delete();

                if (is_array($barcodes)) {
                    foreach ($barcodes as $code) {
                            if (!is_string($code) || empty($code)) continue;

                            if (ProductsBarcodes::where('Supplier_Barcode', $code)->exists()) {
                                return response()->json([
                                    'success' => false,
                                    'message' => "The barcode '{$code}' already exists.",
                                ], 422);
                            }

                            $productBarCode = CodeGenerator::createCode('PRBAR', 'Product_Supplier_BarCode_T', 'Product_Barcode_Code');

                            ProductsBarcodes::create([
                                'Product_Barcode_Code' => $productBarCode,
                                'Products_Id' => $productId,
                                'Supplier_Barcode' => $code,
                                'Created_By' => Auth::id(),
                                'Created_Date' => now(),
                            ]);
                        }
                }

                return response()->json(['message' => 'Barcodes updated successfully']);
            }
}
