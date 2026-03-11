<?php

namespace App\Http\Controllers;

use App\Models\ProductTypes;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;

class ProductTypesController extends Controller
{
    //

    public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = ProductTypes::query();

        // search by name
        if ($search) {
            $query->where('Product_Types_Name', 'like', "%{$search}%");
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Product_Types_Name', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        // return paginator (includes data + links + total + current_page)
        return response()->json(
            $query->paginate($perPage)
        );
    }


    public function index_all()
    {
        return response()->json(
            ProductTypes::orderBy('id', 'DESC')->get()
        );
    }

    public function store(Request $request)
    {

        $pt_code = CodeGenerator::createCode('PRODTYPE', 'Products_Types_Master_T', 'Product_Types_Code');


        ProductTypes::create([
            'Product_Types_Code' => $pt_code,
            'Product_Types_Name' => $request->name,
            'Product_Types_Name_Ar' => $request->name_ar,
            'Created_By' => $request->user()->id,
            'Created_Date' => now(),
        ]);

        return response()->json(['message' => 'Product type created successfully', 'data' => ''], 201);
    }


    public function update(ProductTypes $producttype, Request $request)
    {
        $producttype->Product_Types_Name = $request->name;
        $producttype->Product_Types_Name_Ar = $request->name_ar;

        $producttype->save();

        return response()->json(['message' => 'Product type updated successfully', 'data' => ''], 200);
    }

    public function destroy(ProductTypes $producttype)
    {
        $producttype->delete();

        return response()->json(['message' => 'Product type deleted successfully', 'data' => ''], 200);
    }
}
