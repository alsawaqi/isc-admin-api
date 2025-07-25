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
       return response()->json(ProductManufacture::with('department')->orderBy('id', 'DESC')->get());
    }
    public function store(Request $request)
    {
        $productManufactureCode = CodeGenerator::createCode('MFR', 'Products_Manufacture_Master_T', 'Product_Manufacture_Code');

        ProductManufacture::create([
            'Product_Manufacture_Code' => $productManufactureCode,
            'Products_Manufacture_Name' => $request->name,
            'Products_Manufacture_Name_Ar' => $request->name,
            'Product_Department_Id' => $request->product_department_id,
           
        ]);

        return response()->json(['message' => 'Product manufacture created successfully'], 201);
    }
}
