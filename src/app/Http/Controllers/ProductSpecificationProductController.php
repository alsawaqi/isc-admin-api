<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductMaster;
use Illuminate\Support\Facades\Auth;
use App\Models\ProductSpecificationProduct;
use App\Models\ProductSpecificationDescription;

class ProductSpecificationProductController extends Controller
{
    //
   public function getProductSpecificationsForEdit($productId) {

                    $product = ProductMaster::findOrFail($productId);

                    $subSubDeptId = $product->Product_Sub_Sub_Department_Id;

                    // Get all spec descriptions for this sub-sub-department
                    $descriptions = ProductSpecificationDescription::where('product_sub_sub_department_id', $subSubDeptId)
                                                                      ->get();

                    // Get existing product values
                    $values = ProductSpecificationProduct::where('Product_Id', $productId)
                                                           ->get()
                                                           ->keyBy('Product_Specification_Description_Id');

                  // Combine description with value if exists
                    $merged = $descriptions->map(function ($desc) use ($values) {
                        $existing = $values->get($desc->id);

                        return [
                            'id' => $existing?->id, // This is the ID from Product_Specification_Product_T
                            'product_specification_description_id' => $desc->id,
                            'Product_Specification_Description_Name' => $desc->Product_Specification_Description_Name,
                            'value' => $existing?->value ?? ''
                        ];
                    });

                    return response()->json($merged);
      }



      public function storeOrUpdate(Request $request)
{
    $validated = $request->validate([
        'product_id' => 'required|exists:Products_Master_T,id',
        'specifications' => 'required|array'
    ]);

    foreach ($validated['specifications'] as $spec) {
        $descId = $spec['product_specification_description_id'];
        $value = trim($spec['value']);

        $existing = ProductSpecificationProduct::where('Product_Id', $validated['product_id'])
            ->where('Product_Specification_Description_Id', $descId)
            ->first();

        if ($existing && $value === '') {
            // ❌ Delete if cleared
            $existing->delete();
        } elseif ($existing && $existing->value !== $value) {
            // ✏️ Update if changed
            $existing->value = $value;
            $existing->save();
        } elseif (!$existing && $value !== '') {
            // ➕ Insert new
            ProductSpecificationProduct::create([
                'product_id' => $validated['product_id'],
                'product_specification_description_id' => $descId,
                'value' => $value,
                'Created_By' => Auth::id(),
            ]);
        }
    }

    return response()->json(['message' => 'Specifications synced successfully']);
}
 

    public function store(Request $request)
    {
       

           if ($request->has('specifications')) {

              $specifications = json_decode($request->specifications);

                  foreach ($specifications as $spec) {
                            
                     ProductSpecificationProduct::create([
                                                        'Product_Id' => $request->product_id,
                                                        'Product_Specification_Description_Id' => $spec->product_specification_description_id,
                                                        'value' => $spec->value,
                                                        'Created_By' => Auth::id(),

                                                    ]);
                          }

               }

        return response()->json(['message' => 'Product specifications created successfully'], 201);
    }
}
