<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductDepartments;
use Illuminate\Support\Facades\DB;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use Illuminate\Support\Facades\Storage;

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

 public function bySubDepartment($subDepartmentId){
    $subSubDepartments = ProductSubSubDepartment::where('product_sub_department_id', $subDepartmentId)->get();
    return response()->json($subSubDepartments);
}
 
public function store(Request $request){
       try{

      

            // Validate the request
        $request->validate([
                'name' => 'required|string|max:255',
             
                ]);

            $imagePath = null;
            $imageSize = null;
            $imageExtension = null;
            $imageType = null;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = Storage::disk('r2')->put('department', $file, 'public'); // changed to department
                $imagePath = $path;
                $imageSize = $file->getSize();
                $imageExtension = $file->getClientOriginalExtension();
                $imageType = $file->getMimeType();
            }

            $productDepartmentCode = CodeGenerator::createCode('DEPT', 'Products_Departments_T', 'Product_Department_Code');
        
        
            ProductDepartments::create([
                    'Product_Department_Code' => $productDepartmentCode,
                    'Product_Department_Name' => $request->name,
                    'image_path' => $imagePath,
                    'size' => $imageSize,
                    'extension' => $imageExtension,
                    'type' => $imageType,
                    'Created_By' => $request->user()->id,
                    ]);

   

                     }catch(\Exception $e){
            return response()->json([
                                     'error' => $e->getMessage()
                                    
                                     ], 500);
        }
    }

public function destroy(ProductDepartments $productdepartment){
            try {


                 
                 DB::transaction(function () use ($productdepartment) {
                     //

                     if (!empty($productdepartment->image_path) && Storage::disk('r2')->exists($productdepartment->image_path)) {
                              Storage::disk('r2')->delete($productdepartment->image_path);
                            }


                // Delete the product department
                $productdepartment->delete();

                // Return a success response
                return response()->json(['message' => 'Product department deleted successfully'], 200);
                 });
                 
                  
            } catch (\Exception $e) {
                // Handle any errors that occur during deletion
                return response()->json(['error' => $e->getMessage()], 500);
        }
   }
}
