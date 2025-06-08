<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductManufacture;

class ProductManufactureController extends Controller
{
    //

    public function index()
    {
        return response()->json(
            ProductManufacture::orderBy('id', 'DESC')->get()
        );
    }
    public function store(Request $request)
    {
        $productManufactureCode = CodeGenerator::createCode('MFR', 'Products_Manufacture_Master_T', 'Product_Manufacture_Code');

        ProductManufacture::create([
            'Product_Manufacture_Code' => $productManufactureCode,
            'name' => $request->name,
            
        ]);

        return response()->json(['message' => 'Product manufacture created successfully'], 201);
    }
}
