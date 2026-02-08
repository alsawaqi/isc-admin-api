<?php

namespace App\Http\Controllers;

use App\Models\ProductImages;
use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use Illuminate\Http\Request;
// If you want approve → move to master tables, import your real models:
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Helpers\CodeGenerator;
use App\Models\ProductVendorRequest;

class AdminTempProductController extends Controller
{

/**
 * Core approval logic for a single temporary product.
 *
 * - Creates master product + images
 * - Updates temp record (approved, reviewed_by, etc.)
 * - Logs into Products_Vendor_Requests_T
 * - Back-fills Products_Id on ALL previous logs for this temp product
 * - Soft deletes temp product + its temp images
 *
 * Returns: approved master product id
 *
 * @throws \Throwable
 */
private function approveOne(ProductTemporary $temp): int
{
    // Make sure images relation is loaded (safe even if already eager-loaded)
    $temp->loadMissing('images');

    // If already approved, just return existing Approved_Product_Id (if present)
    if ($temp->Submission_Status === 'approved' && $temp->Approved_Product_Id) {
        return $temp->Approved_Product_Id;
    }

    $approvedProductId = 0;

    DB::transaction(function () use ($temp, &$approvedProductId) {

        // 1) Generate Product_Code like admin store()
        $productMasterCode = CodeGenerator::createCode('PROD', 'Products_Master_T', 'Product_Code');

        // 2) Create product in master
        $master = ProductMaster::create([
            'Product_Code' => $productMasterCode,

            'Product_Department_Id'         => $temp->Product_Department_Id,
            'Product_Sub_Department_Id'     => $temp->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $temp->Product_Sub_Sub_Department_Id,

            'Product_Type_Id'        => $temp->Product_Type_Id,
            'Product_Brand_Id'       => $temp->Product_Brand_Id,
            'Product_Manufacture_Id' => $temp->Product_Manufacture_Id,

            'Product_Name'        => $temp->Product_Name,
            'Product_Name_Ar'     => $temp->Product_Name_Ar,
            'Product_Description' => $temp->Description,

            'Product_Price' => $temp->Product_Price,
            'Product_Stock' => $temp->Product_Stock,

            // ⚠️ IMPORTANT: must match your CHECK constraint on Status
            // If your constraint only allows e.g. 'Active', 'Inactive', etc,
            // change this to one of those allowed values.
            'Status' => 'available',

            // dimensions
            'Weight_Kg'  => $temp->Weight_Kg,
            'Length_Cm'  => $temp->Length_Cm,
            'Width_Cm'   => $temp->Width_Cm,
            'Height_Cm'  => $temp->Height_Cm,
            'Volume_Cbm' => $temp->Volume_Cbm,

            // vendor owner
            'Vendor_Id' => $temp->Vendor_Id,

            // audit
            'Created_By'   => Auth::id(),
            'Created_Date' => now(),
        ]);

        $approvedProductId = $master->id;

        // 3) Inhouse barcode
        $suffix         = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $inhouseBarcode = $master->id . '-' . $suffix;

        $master->update([
            'Inhouse_Barcode_Source' => $inhouseBarcode,
        ]);

        // 4) Copy temp images -> Products_Images_T (NO upload)
        foreach ($temp->images as $img) {
            ProductImages::create([
                'Product_Image_Code' => CodeGenerator::createCode('PIMG', 'Products_Images_T', 'Product_Image_Code'),
                'Products_Id'        => $master->id,

                'Image_Path'      => $img->Image_Path,
                'Image_Size'      => $img->Image_Size,
                'Image_Extension' => $img->Image_Extension,
                'Image_Type'      => $img->Image_Type,

                'Created_By'   => Auth::id(),
                'Created_Date' => now(),
            ]);
        }

        // 5) Update temp status
        $temp->update([
            'Submission_Status'   => 'approved',
            'Reviewed_By'         => Auth::id(),
            'Reviewed_At'         => now(),
            'Approved_Product_Id' => $master->id,
            'Rejection_Reason'    => null,
        ]);

        // 6) Log APPROVED in Products_Vendor_Requests_T
        ProductVendorRequest::create([
            'Products_Temporary_Id' => $temp->id,
            'Products_Id'           => $master->id,
            'Vendor_Id'             => $temp->Vendor_Id,

            'Status'  => 'approved',
            'Comment' => null,

            'Action_By_User_Id' => Auth::id(),
            'Action_By_Role'    => 'admin',
            'Action_At'         => now(),
        ]);

        // 7) Back-fill Products_Id for ALL previous logs of this temp product
        ProductVendorRequest::where('Products_Temporary_Id', $temp->id)
            ->update(['Products_Id' => $master->id]);

        // 8) Soft delete temp product + temp images
        $temp->images()->delete(); // soft delete all related temp images
        $temp->delete();           // soft delete the temp product
    });

    return $approvedProductId;
}





    /**
     * 1) List vendors who have temp submissions (group by Vendor_Id)
     * Optional query: ?status=pending|rejected|approved.
     */
    public function vendors(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = trim((string) $request->get('search', ''));
        $status = (string) $request->get('status', 'all'); // pending | all | ...
        $sortBy = (string) $request->get('sortBy', 'last_submitted_at');
        $sortDir = strtolower((string) $request->get('sortDir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = ['Vendor_Name', 'Vendor_Code', 'requests_count', 'pending_count', 'last_submitted_at'];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'last_submitted_at';
        }

        $q = DB::table('Products_Temporary_T as pt')
            ->join('Vendors_Master_T as v', 'v.id', '=', 'pt.Vendor_Id')
            ->whereNull('pt.deleted_at')
            ->whereNull('v.deleted_at')
            ->when($status !== 'all', function ($qq) use ($status) {
                $qq->where('pt.Submission_Status', $status);
            })
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($w) use ($search) {
                    $w->where('v.Vendor_Name', 'like', "%{$search}%")
                        ->orWhere('v.Trade_Name', 'like', "%{$search}%")
                        ->orWhere('v.Vendor_Code', 'like', "%{$search}%");
                });
            })
            ->select([
                'pt.Vendor_Id',
                'v.Vendor_Name',
                'v.Trade_Name',
                'v.Vendor_Code',
                DB::raw('COUNT(*) as requests_count'),
                DB::raw("SUM(CASE WHEN pt.Submission_Status = 'pending' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw('MAX(pt.Submitted_At) as last_submitted_at'),
            ])
            ->groupBy('pt.Vendor_Id', 'v.Vendor_Name', 'v.Trade_Name', 'v.Vendor_Code')
            ->orderBy($sortBy, $sortDir);

        // returns: data, total, from, to, last_page... (same style you use)
        return response()->json($q->paginate($perPage));
    }

    /**
     * 2) List temp products for a given vendor
     * Optional query: ?status=pending|rejected|approved.
     */
    public function vendorProducts(Request $request, int $vendorId)
    {
        $perPage = (int) $request->get('per_page', 20);
        $search = trim((string) $request->get('search', ''));
        $status = (string) $request->get('status', 'all');
        $sortBy = (string) $request->get('sortBy', 'Submitted_At');
        $sortDir = strtolower((string) $request->get('sortDir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = ['id', 'Temp_Product_Code', 'Product_Name', 'Submission_Status', 'Submitted_At', 'Product_Price', 'Product_Stock'];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'Submitted_At';
        }

        $q = ProductTemporary::query()
            ->where('Vendor_Id', $vendorId)
            ->with(['defaultImage'])
            ->when($status !== 'all', fn ($qq) => $qq->where('Submission_Status', $status))
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($w) use ($search) {
                    $w->where('Product_Name', 'like', "%{$search}%")
                      ->orWhere('Temp_Product_Code', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortBy, $sortDir);

        return response()->json($q->paginate($perPage));
    }

    /**
     * 3) Show temp product details + all images + vendor.
     */
    public function show(int $tempId)
    { 

        
        $product = ProductTemporary::query()
        ->with([
            'vendor',
            'images',
            'defaultImage',
    
            // ✅ specs + their name/value
            'specs.description',
            'specs.value',
    
            // optional (only if you created those relations)
            'department',
            'subDepartment',
            'subSubDepartment',
            'brand',
            'manufacture',
            'type',
        ])
        ->findOrFail($tempId);
    

        return response()->json(['data' => $product]);
    }

    /**
     * 4) Reject with reason.
     */
    public function reject(Request $request, int $tempId)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        $product = ProductTemporary::findOrFail($tempId);

        DB::transaction(function () use ($product, $data) {
            // Update temp product
            $product->update([
                'Submission_Status' => 'rejected',
                'Rejection_Reason' => $data['reason'],
                'Reviewed_By' => Auth::id(),
                'Reviewed_At' => now(),
            ]);

            // 🔹 Log timeline entry
            ProductVendorRequest::create([
                'Products_Temporary_Id' => $product->id,
                'Products_Id'           => $product->Approved_Product_Id ?? null, // usually null here
                'Vendor_Id'             => $product->Vendor_Id,

                'Status'  => 'rejected',
                'Comment' => $data['reason'],

                'Action_By_User_Id' => Auth::id(),
                'Action_By_Role'    => 'admin',
                'Action_At'         => now(),
            ]);
        });

        return response()->json(['message' => 'Rejected successfully.']);
    }

 


    /**
     * 5) Approve: mark approved + (optionally) copy to Products_Master_T + Products_Images_T.
     */
    public function approve(int $tempId)
    {
        try {
            // If ProductTemporary uses SoftDeletes, this will also see soft-deleted rows
            $temp = ProductTemporary::withTrashed()
                ->with(['images'])
                ->find($tempId);
    
            if (! $temp) {
                return response()->json([
                    'message' => "Temporary product not found (id: {$tempId})."
                ], 404);
            }
    
            $approvedProductId = $this->approveOne($temp);
    
            return response()->json([
                'message'             => 'Approved successfully.',
                'approved_product_id' => $approvedProductId,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Approval failed: ' . $e->getMessage()], 500);
        }
    }
    
    




    public function review(Request $request, int $tempId)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:3'],
        ]);
    
        $product = ProductTemporary::findOrFail($tempId);
    
        DB::transaction(function () use ($product, $data) {
            // Update temp product
            $product->update([
                'Submission_Status' => 'needs_changes',     // reviewed but not rejected
                'Rejection_Reason'  => $data['note'],       // reuse column as review note
                'Reviewed_By'       => Auth::id(),
                'Reviewed_At'       => now(),
            ]);
    
            // 🔹 Log timeline entry (changes requested)
            ProductVendorRequest::create([
                'Products_Temporary_Id' => $product->id,
                'Products_Id'           => $product->Approved_Product_Id ?? null,
                'Vendor_Id'             => $product->Vendor_Id,
    
                'Status'  => 'changes_requested',
                'Comment' => $data['note'],
    
                'Action_By_User_Id' => Auth::id(),
                'Action_By_Role'    => 'admin',
                'Action_At'         => now(),
            ]);
        });
    
        return response()->json(['message' => 'Review saved successfully.']);
    }
    

    public function bulkApprove(Request $request)
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
    
        $ids = $data['ids'];
    
        $approved = [];
        $failed   = [];
    
        // Include soft-deleted temp products as well
        $temps = ProductTemporary::withTrashed()
            ->with(['images'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    
        foreach ($ids as $id) {
            if (! isset($temps[$id])) {
                $failed[] = ['id' => $id, 'error' => 'Temporary product not found.'];
                continue;
            }
    
            $temp = $temps[$id];
    
            try {
                $this->approveOne($temp);
                $approved[] = $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
    
        return response()->json([
            'message'       => 'Bulk approve finished.',
            'approved_ids'  => $approved,
            'failed'        => $failed,
        ]);
    }
    


    public function bulkReject(Request $request)
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
            'reason' => ['required', 'string', 'min:3'],
        ]);

        DB::transaction(function () use ($data) {
            // Fetch products first so we know Vendor_Id, Approved_Product_Id, etc.
            $products = ProductTemporary::whereIn('id', $data['ids'])->get();

            // Update temp records
            ProductTemporary::whereIn('id', $data['ids'])->update([
                'Submission_Status' => 'rejected',
                'Rejection_Reason'  => $data['reason'],
                'Reviewed_By'       => Auth::id(),
                'Reviewed_At'       => now(),
            ]);

            // 🔹 Log a rejected entry for each product
            foreach ($products as $product) {
                ProductVendorRequest::create([
                    'Products_Temporary_Id' => $product->id,
                    'Products_Id'           => $product->Approved_Product_Id ?? null,
                    'Vendor_Id'             => $product->Vendor_Id,

                    'Status'  => 'rejected',
                    'Comment' => $data['reason'],

                    'Action_By_User_Id' => Auth::id(),
                    'Action_By_Role'    => 'admin',
                    'Action_At'         => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Bulk reject finished.']);
    }

}
