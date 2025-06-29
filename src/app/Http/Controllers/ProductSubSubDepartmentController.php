<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductDepartments;
use Illuminate\Support\Facades\DB;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use Illuminate\Support\Facades\Storage;

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


public function getFullDepartmentTree(){

    $departments = ProductDepartments::with([
        'subDepartments' => function ($subQuery) {
            $subQuery->select(
                'id',
                'product_department_id',
                'Product_Sub_Department_Code',
                'name',
                'description'
            )
            ->with(['subSubDepartments' => function ($subSubQuery) {
                $subSubQuery->select(
                    'id',
                    'product_sub_department_id',
                    'Product_Sub_Sub_Department_Code',
                    'name',
                    'description',
                    'image_path'
                );
            }]);
        }
    ])->select(
        'id',
        'Product_Department_Code',
        'Product_Department_Name',
        'Product_Department_Name_Ar',
        'image_path'
    )->get();

    return response()->json($departments);
}


public function store(Request $request){

     
    
    

        $request->validate([
        'product_sub_department_id' => 'required|exists:Products_Sub_Department_T,id',
        'name' => 'required|string|max:255',
         ]);
  
         
     $result = DB::transaction(function () use ($request) {
            
               
        
         

          $imagePath = null;
            $imageSize = null;
            $imageExtension = null;
            $imageType = null;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = Storage::disk('r2')->put('subsubdepartment', $file, 'public'); // changed to department
                $imagePath = $path;
                $imageSize = $file->getSize();
                $imageExtension = $file->getClientOriginalExtension();
                $imageType = $file->getMimeType();
            }

    $productSubDepartmentCode = CodeGenerator::createCode('SUBSUBDEPT', 'Products_Sub_Sub_Department_T', 'Product_Sub_Sub_Department_Code');

      
    $new = ProductSubSubDepartment::create([
        'Product_Sub_Sub_Department_Code' => $productSubDepartmentCode,
        'product_sub_department_id' => $request->product_sub_department_id,
        'name' => $request->name,
         'image_path' => $imagePath,
                    'size' => $imageSize,
                    'extension' => $imageExtension,
                    'type' => $imageType,
         
    ]);


     });

    return response()->json([
        'message' => 'Sub Sub Department created successfully',
        'data' => $result
    ]);
        
    }
}
