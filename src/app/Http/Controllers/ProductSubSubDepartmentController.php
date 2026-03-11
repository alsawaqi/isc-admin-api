<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\ProductDepartments;
use Illuminate\Support\Facades\DB;
use App\Models\productsubsub;
use App\Models\ProductSubSubDepartment;
use Illuminate\Support\Facades\Storage;

class ProductSubSubDepartmentController extends Controller
{
    //

    public function index()
    {
        return response()->json(
            ProductSubSubDepartment::with('productsubsub', 'productsubsub.productDepartment')
                ->orderBy('id', 'DESC')
                ->get()
        );
    }


    public function getFullDepartmentTree()
    {

        $departments = ProductDepartments::with([
            'subDepartments' => function ($subQuery) {
                $subQuery->select(
                    'id',
                    'Products_Departments_Id',
                    'Products_Sub_Department_Code',
                    'Sub_Department_Name',
                    'Sub_Department_Name_Ar',

                )
                    ->with([
                        'subSubDepartments' => function ($subSubQuery) {
                        $subSubQuery->select(
                            'id',
                            'Product_Sub_Department_Id',
                            'Product_Sub_Sub_Department_Code',
                            'Product_Sub_Sub_Department_Name',
                            'Product_Sub_Sub_Department_Name_Ar',
                            'Product_Sub_Sub_Department_Description',
                            'Image_Path',
                            'View_Options'
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


    public function store(Request $request)
    {

        $request->validate([
            'Product_Sub_Department_Id' => 'required|exists:Products_Sub_Department_T,id',
            'Product_Sub_Sub_Department_Name' => 'required|string|max:255',
        ]);


        try {

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

                $productsubsubCode = CodeGenerator::createCode('SUBSUBDEPT', 'Products_Sub_Sub_Department_T', 'Product_Sub_Sub_Department_Code');


              ProductSubSubDepartment::create([
                    'Product_Sub_Sub_Department_Code' => $productsubsubCode,
                    'Product_Sub_Department_Id' => $request->Product_Sub_Department_Id,
                    'Product_Sub_Sub_Department_Description' => $request->description,
                    'Product_Sub_Sub_Department_Name' => $request->Product_Sub_Sub_Department_Name,
                    'Product_Sub_Sub_Department_Name_Ar' => $request->Product_Sub_Sub_Department_Name_Ar,
                    'Image_Path' => $imagePath,
                    'Image_Size' => $imageSize,
                    'Image_Extension' => $imageExtension,
                    'Image_Type' => $imageType,
                    'View_Options' => $request->View_Options,
                    'Created_Date' => now(),
                    'Created_By' => $request->user()->id,
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Sub Sub Department created successfully',
            'data' => $result
        ]);
    }



    public function update(ProductSubSubDepartment $subsub, Request $request)
    {
        try {
            // basic fields
            $subsub->Product_Sub_Sub_Department_Name = $request->input('Product_Sub_Sub_Department_Name', $subsub->Product_Sub_Sub_Department_Name);
            $subsub->Product_Sub_Sub_Department_Name_Ar = $request->input('Product_Sub_Sub_Department_Name_Ar', $subsub->Product_Sub_Sub_Department_Name_Ar);
            $subsub->Product_Sub_Sub_Department_Description = $request->input('description', $subsub->Product_Sub_Sub_Department_Description);
            $subsub->View_Options = $request->input('View_Options', $subsub->View_Options);
            if ($request->has('Product_Sub_Department_Id')) {
                $subsub->Product_Sub_Department_Id = $request->input('Product_Sub_Department_Id');
            }

            // image upload new
            if ($request->hasFile('image')) {
                if ($subsub->Image_Path) {
                    Storage::disk('r2')->delete($subsub->Image_Path);
                }
                $file = $request->file('image');
                $path = Storage::disk('r2')->put('subsubdepartment', $file, 'public');
                $subsub->Image_Path      = $path;
                $subsub->Image_Size      = $file->getSize();
                $subsub->Image_Extension = $file->getClientOriginalExtension();
                $subsub->Image_Type      = $file->getMimeType();
            } elseif ($request->input('remove_image') === '1') {
                if ($subsub->Image_Path) {
                    Storage::disk('r2')->delete($subsub->Image_Path);
                }
                $subsub->Image_Path      = null;
                $subsub->Image_Size      = null;
                $subsub->Image_Extension = null;
                $subsub->Image_Type      = null;
            }

            $subsub->save();

            return response()->json([
                'success' => true,
                'message' => 'Updated',
                'data' => $subsub,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function destroy(ProductSubSubDepartment $productsubsub)
    {
        try {
            DB::transaction(function () use ($productsubsub) {
                // Delete the sub-sub-department
                if (!empty($productsubsub->image_path) && Storage::disk('r2')->exists($productsubsub->image_path)) {
                    Storage::disk('r2')->delete($productsubsub->image_path);
                }
                $productsubsub->delete();
            });

            return response()->json(['message' => 'Sub Sub Department deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
