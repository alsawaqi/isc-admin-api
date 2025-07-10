<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ProductSpecificationDescription;

class ProductSpecificationDescriptionController extends Controller
{
    //
public function index(Request $request)
    {
        return response()->json(
            ProductSpecificationDescription::with('subSubDepartment')
                ->where('product_sub_sub_department_id', $request->product_sub_sub_department_id)
                ->orderBy('id', 'DESC')
                ->get()
        );
    }

  
 
public function store(Request $request)
{
    $request->validate([
    'specifications' => 'required|array',
    'specifications.*' => 'required|string',
    'sub_sub_category_id' => 'required|integer|exists:Products_Sub_Sub_Department_T,id',
]);

$data = [];

foreach ($request->specifications as $spec) {
    $data[] = [
        'name' => $spec,
        'product_sub_sub_department_id' => $request->sub_sub_category_id,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

DB::table('Product_Specification_Description_T')->insert($data);

return response()->json(['message' => 'Inserted successfully']);
}


}
