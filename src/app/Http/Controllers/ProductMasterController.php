<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductMaster;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;

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
        $productMasterCode = CodeGenerator::createCode('PROD', 'Products_Master_T', 'Product_Code');

        ProductMaster::create([
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
                                'Created_By' => Auth::id()// Optional, if using auth
        ]);

        return response()->json(['message' => 'Product created successfully'], 201);
    }
}
