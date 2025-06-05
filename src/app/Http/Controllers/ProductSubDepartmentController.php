<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;

class ProductSubDepartmentController extends Controller
{
    //

    public function index()
    {
        return response()->json(
            ProductSubDepartment::with('productDepartment')
                                 ->orderBy('id', 'DESC')
                                  ->get()
        );
    }

    public function store(Request $request)
    {
        $productSubDepartmentCode = CodeGenerator::createCode('SUBDEPT', 'Products_Sub_Department_T', 'Product_Sub_Department_Code');

        ProductSubDepartment::create([
            'Product_Sub_Department_Code' => $productSubDepartmentCode,
            'product_department_id' => $request->product_department_id,
            'name' => $request->name,
            'image_path' => 'sss'
         
        ]);
    }


   
}
