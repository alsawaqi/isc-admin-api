<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductSubSubDepartment;

class ProductSubSubDepartmentController extends Controller
{
    //

    public function index(){
        return response()->json(
            ProductSubSubDepartment::with('productSubDepartment' ,'productSubDepartment.productDepartment')
                                   ->orderBy('id', 'DESC')
                                   ->get()
        );
    }


     public function  store(Request $request)
    {


        $request->validate([
        'product_sub_department_id' => 'required|exists:Products_Sub_Department_T,id',
        'name' => 'required|string|max:255',
    ]);

    $productSubDepartmentCode = CodeGenerator::createCode('SUBSUBDEPT', 'Products_Sub_Sub_Department_T', 'Product_Sub_Sub_Department_Code');

      
    $new = ProductSubSubDepartment::create([
        'Product_Sub_Sub_Department_Code' => $productSubDepartmentCode,
        'product_sub_department_id' => $request->product_sub_department_id,
        'name' => $request->name,
        'image_path' => 'sss'
    ]);

    return response()->json([
        'message' => 'Sub Sub Department created successfully',
        'data' => $new
    ]);
        
    }
}
