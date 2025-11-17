<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductManufacture;

class ProductManufactureController extends Controller
{
    //

    public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);


        $query = ProductManufacture::query();
        
        $query->with('department');

        //search by name
        if ($search) {
            $query->where('Products_Manufacture_Name', 'like', "%{$search}%");       
         }

        // whitelist sortable columns
        if (!in_array($sortBy, ['id', 'Products_Manufacture_Name', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );


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

    public function update(Request $request, ProductManufacture $productmanufacture)
    {
        $productmanufacture->Products_Manufacture_Name = $request->name;
        $productmanufacture->Products_Manufacture_Name_Ar = $request->name;
        if ($request->has('product_department_id')) {
            $productmanufacture->Product_Department_Id = $request->product_department_id;
        }
        $productmanufacture->save();

        return response()->json(['message' => 'Product manufacture updated successfully'], 200);
    }

  public function destroy(ProductManufacture $productmanufacture)
    {
        $productmanufacture->delete();

        return response()->json(['message' => 'Product manufacture deleted successfully'], 200);
    }
}
