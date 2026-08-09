<?php

namespace App\Http\Controllers;

use App\Models\ProductDepartments;
use App\Models\ProductSubSubDepartment;
use App\Services\ProductHierarchyManualAllocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductSubSubDepartmentController extends Controller
{
    //

    public function index()
    {
        return response()->json(
            ProductSubSubDepartment::with('productsubsub', 'productsubsub.productDepartment')
                ->orderBy('Product_Sub_Department_Id')
                ->displayOrdered()
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
                    'Display_Order',
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
                                'View_Options',
                                'Display_Order'
                            )->displayOrdered();
                        }])
                    ->displayOrdered();
            },
        ])->select(
            'id',
            'Product_Department_Code',
            'Product_Department_Name',
            'Product_Department_Name_Ar',
            'image_path',
            'Display_Order'
        )->displayOrdered()->get();

        return response()->json($departments);
    }

    public function store(Request $request, ProductHierarchyManualAllocationService $allocator)
    {

        $request->validate([
            'Product_Sub_Department_Id' => 'required|exists:Products_Sub_Department_T,id',
            'Product_Sub_Sub_Department_Name' => 'required|string|max:255',
        ]);

        try {

            $imagePath = null;
            $imageSize = null;
            $imageExtension = null;
            $imageType = null;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = Storage::disk('uploads')->put('subsubdepartment', $file, 'public');
                $imagePath = $path;
                $imageSize = $file->getSize();
                $imageExtension = $file->getClientOriginalExtension();
                $imageType = $file->getMimeType();
            }

            $result = $allocator->createSubSubDepartment((int) $request->Product_Sub_Department_Id, [
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
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Sub-subcategory created successfully',
            'data' => $result,
        ]);
    }

    public function update(
        ProductSubSubDepartment $subsub,
        Request $request,
        ProductHierarchyManualAllocationService $allocator,
    ) {
        $request->validate([
            'Product_Sub_Department_Id' => 'sometimes|required|integer|exists:Products_Sub_Department_T,id',
        ]);

        try {
            $attributes = [
                'Product_Sub_Sub_Department_Name' => $request->input('Product_Sub_Sub_Department_Name', $subsub->Product_Sub_Sub_Department_Name),
                'Product_Sub_Sub_Department_Name_Ar' => $request->input('Product_Sub_Sub_Department_Name_Ar', $subsub->Product_Sub_Sub_Department_Name_Ar),
                'Product_Sub_Sub_Department_Description' => $request->input('description', $subsub->Product_Sub_Sub_Department_Description),
                'View_Options' => $request->input('View_Options', $subsub->View_Options),
            ];

            // image upload new
            if ($request->hasFile('image')) {
                if ($subsub->Image_Path) {
                    Storage::disk('uploads')->delete($subsub->Image_Path);
                }
                $file = $request->file('image');
                $path = Storage::disk('uploads')->put('subsubdepartment', $file, 'public');
                $attributes['Image_Path'] = $path;
                $attributes['Image_Size'] = $file->getSize();
                $attributes['Image_Extension'] = $file->getClientOriginalExtension();
                $attributes['Image_Type'] = $file->getMimeType();
            } elseif ($request->input('remove_image') === '1') {
                if ($subsub->Image_Path) {
                    Storage::disk('uploads')->delete($subsub->Image_Path);
                }
                $attributes['Image_Path'] = null;
                $attributes['Image_Size'] = null;
                $attributes['Image_Extension'] = null;
                $attributes['Image_Type'] = null;
            }

            $result = $allocator->updateSubSubDepartment(
                $subsub,
                (int) $request->input(
                    'Product_Sub_Department_Id',
                    $subsub->Product_Sub_Department_Id,
                ),
                $attributes,
            );

            return response()->json([
                'success' => true,
                'message' => 'Updated',
                'data' => $result,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(
        ProductSubSubDepartment $productsubsub,
        \App\Services\ProductHierarchyDisplayOrderService $ordering,
    ) {
        try {
            DB::transaction(function () use ($productsubsub, $ordering) {
                $ordering->acquireHierarchyLock();
                $ordering->lockRevisionState();
                // Delete the sub-sub-department
                if (! empty($productsubsub->image_path) && Storage::disk('uploads')->exists($productsubsub->image_path)) {
                    Storage::disk('uploads')->delete($productsubsub->image_path);
                }
                $productsubsub->delete();
                $ordering->incrementRevision();
            }, 3);

            return response()->json(['message' => 'Sub-subcategory deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
