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
                'Products_Departments_Id',
                'Sub_Department_Name',
                'Sub_Department_Name_Ar',
                'Image_path',
            );
        }])->select(
            'id',
            'Product_Department_Code',
            'Product_Department_Name',

            'created_at',
            'updated_at'
        )->get();

        return response()->json($departments);
    }

    public function store(Request $request)
    {

        DB::transaction(function () use ($request) {

            
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

            $productSubDepartmentCode = CodeGenerator::createCode('SUBDEPT', 'Products_Sub_Department_T', 'Products_Sub_Department_Code');

            ProductSubDepartment::create([
                'Products_Departments_Id' => $request->product_department_id,
                'Products_Sub_Department_Code' => $productSubDepartmentCode,
                'Sub_Department_Name' => $request->name,
                'Sub_Department_Name_Ar' => $request->namear,
                'Image_path' => $imagePath,
                'Image_Size' => $imageSize,
                'Image_Extension' => $imageExtension,
                'Image_Type' => $imageType,
                'Created_By' => $request->user()->id,
            ]);
        });
        return response()->json(['message' => 'Product Sub Department created successfully'], 201);
    }


    public function update(ProductSubDepartment $productsubdepartment, Request $request)
    {
        try {
            $productsubdepartment->Sub_Department_Name = $request->name;
            $productsubdepartment->Sub_Department_Name_Ar = $request->namear;

            if ($request->hasFile('image')) {
                if ($productsubdepartment->Image_path) {
                    Storage::disk('r2')->delete($productsubdepartment->Image_path);
                }

                $file = $request->file('image');
                $path = Storage::disk('r2')->put('subdepartment', $file, 'public');

                $productsubdepartment->Image_path      = $path;
                $productsubdepartment->Image_Size      = $file->getSize();
                $productsubdepartment->Image_Extension = $file->getClientOriginalExtension();
                $productsubdepartment->Image_Type      = $file->getMimeType();
            }

            $productsubdepartment->save();

            return response()->json([
                'message' => 'Product Sub Department updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating Product Sub Department: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(ProductSubDepartment $productsubdepartment)
    {

        try {

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
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting Product Sub Department: ' . $e->getMessage(),
            ], 500);
        }
    }
}
