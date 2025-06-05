<?php

namespace App\Http\Controllers;

use App\Models\ProductTypes;
use Illuminate\Http\Request;

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
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
         
        ]);

        $productType = new  ProductTypes();
        $productType->name = $validatedData['name'];
   
        $productType->save();

        return response()->json(['message' => 'Product type created successfully', 'data' => 's'], 201);
    }
}
