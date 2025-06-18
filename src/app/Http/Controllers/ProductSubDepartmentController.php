<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductDepartments;
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


        public function getWithSubDepartments()
    {
        $departments = ProductDepartments::with(['subDepartments' => function ($query) {
            $query->select(
                'id',
                'Product_Sub_Department_Code',
                'product_department_id',
                'name',
                'description',
                'image_path',
                'size',
                'extension',
                'type',
                'created_at',
                'updated_at'
            );
        }])->select(
            'id',
            'Product_Department_Code',
            'Product_Department_Name',
            'Product_Department_Name_Ar',
            'Touch_Screen_Status',
            'Stock_Control_Status',
            'image_path',
            'size',
            'extension',
            'type',
            'updated_status',
            'Created_By',
            'created_at',
            'updated_at'
        )->get();

        return response()->json($departments);
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
