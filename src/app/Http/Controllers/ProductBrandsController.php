<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductBrands;

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
        $productBrandCode = CodeGenerator::createCode('BRND', 'Products_Brands_Master_T', 'Product_Brand_Code');

        ProductBrands::create([
            'Product_Brand_Code' => $productBrandCode,
            'name' => $request->name,
         ]);

        return response()->json(['message' => 'Product brand created successfully'], 201);
    }
    //
}
