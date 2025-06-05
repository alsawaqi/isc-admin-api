<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductDepartments;
use App\Models\ProductSubDepartment;

class ProductDepartmentsController extends Controller
{
    //

     public function index(){
        return response()->json(
                                ProductDepartments::orderBy('id','DESC')
                                                     ->get()
                                                    );

     }


     public function getSubDepartments($departmentId)
{
    $subDepartments = ProductSubDepartment::where('product_department_id', $departmentId)->get();

    return response()->json([
        'sub_departments' => $subDepartments
    ]);
}
 
    public function store(Request $request)
    {

     $productDepartmentCode = CodeGenerator::createCode('DEPT', 'Products_Departments_T', 'Product_Department_Code');
  
  
     ProductDepartments::create([
             'Product_Department_Code' => $productDepartmentCode,
            'Product_Department_Name' => $request->name,
            'Created_By' => $request->user()->id,
            ]);
    }
}
