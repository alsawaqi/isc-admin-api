<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductDepartments;
use Illuminate\Support\Facades\DB;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use Illuminate\Support\Facades\Storage;

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

            $imagePath = null;
            $imageSize = null;
            $imageExtension = null;
            $imageType = null;


             if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = Storage::disk('r2')->put('subdepartment', $file, 'public'); // changed to department
                $imagePath = $path;
                $imageSize = $file->getSize();
                $imageExtension = $file->getClientOriginalExtension();
                $imageType = $file->getMimeType();
            }

        $productSubDepartmentCode = CodeGenerator::createCode('SUBDEPT', 'Products_Sub_Department_T', 'Product_Sub_Department_Code');

        ProductSubDepartment::create([
            'Product_Sub_Department_Code' => $productSubDepartmentCode,
            'product_department_id' => $request->product_department_id,
            'name' => $request->name,
            'image_path' => $imagePath,
                    'size' => $imageSize,
                    'extension' => $imageExtension,
                    'type' => $imageType,
         
        ]);
    }


    public function destroy(ProductSubDepartment $productsubdepartment)
    {

       try{
      
         $result = DB::transaction(function () use ($productsubdepartment) {
            // Delete related sub-sub-departments


                   if (!empty($productsubdepartment->image_path) && Storage::disk('r2')->exists($productsubdepartment->image_path)) {
                              Storage::disk('r2')->delete($productsubdepartment->image_path);
                      }


                $productsubdepartment->delete();


        


         });


          return response()->json([
            'message' => $result,
        ]);

       

       }catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting Product Sub Department: ' . $e->getMessage(),
            ], 500);
        }
         
         

        
    }

   
}
