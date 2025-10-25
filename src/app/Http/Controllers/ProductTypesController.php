<?php

namespace App\Http\Controllers;

use App\Models\ProductTypes;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;

class ProductTypesController extends Controller
{
    //

    public function index()
    {
        $productTypes = ProductTypes::orderBy('id', 'DESC')->get();

        return response()->json(['data' => $productTypes], 200);
    }

    public function store(Request $request)
    {

       $pt_code = CodeGenerator::createCode('PRODTYPE', 'Products_Types_Master_T', 'Product_Types_Code');
        
        
        ProductTypes::create([
           'Product_Types_Code' => $pt_code,
            'Product_Types_Name' => $request->name,
            'Created_By' => $request->user()->id,
            'Created_Date' => now(),
        ]);

        return response()->json(['message' => 'Product type created successfully', 'data' => ''], 201);
    }


    public function update(ProductTypes $producttype, Request $request)
    {
        $producttype->Product_Types_Name = $request->name;
       
        $producttype->save();

        return response()->json(['message' => 'Product type updated successfully', 'data' => ''], 200);
    }
}
