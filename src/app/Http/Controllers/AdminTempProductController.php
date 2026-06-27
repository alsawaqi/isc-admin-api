<?php

namespace App\Http\Controllers;

use App\Models\ProductImages;
use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use Illuminate\Http\Request;
// If you want approve → move to master tables, import your real models:
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Helpers\CodeGenerator;
use App\Models\ProductSpecificationProduct;
use App\Models\ProductSpecificationDescription;
use App\Models\ProductSpecificationValue;
use App\Models\ProductVendorRequest;
use App\Support\Vendors\VendorApprovalSla;

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
    $temp->loadMissing(['images', 'specs']);

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

        foreach ($temp->specs as $spec) {
            ProductSpecificationProduct::create([
                'Product_Id' => $master->id,
                'Product_Specification_Description_Id' => $spec->Product_Specification_Description_Id,
                'product_specification_value_id' => $spec->product_specification_value_id,
                'Created_By' => Auth::id(),
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

            'Request_Type' => 'new_product',
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
        $page = $q->paginate($perPage);

        // Rows here are grouped vendor summaries (DB::table -> stdClass), not
        // ProductTemporary models, so no per-product transform/SLA applies.
        // (A transform type-hinted to ProductTemporary here threw a TypeError
        // -> 500, which silently emptied the admin vendor-requests queue.)
        return response()->json($page);
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

        
        $product = ProductTemporary::withTrashed()
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
    

        $product->setAttribute('approval_sla', VendorApprovalSla::forProduct($product));

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

                'Request_Type' => 'new_product',
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

                'Request_Type' => 'new_product',
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

                    'Request_Type' => 'new_product',
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

    public function approvedUpdateRequests(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = trim((string) $request->get('search', ''));
        $status = (string) $request->get('status', 'open');

        if (! $this->productUpdateRequestColumnsReady()) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'from' => null,
                'to' => null,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'message' => 'Vendor product update request columns are not migrated yet.',
            ]);
        }

        $q = ProductVendorRequest::query()
            ->with([
                'vendor:id,Vendor_Code,Vendor_Name,Trade_Name',
                'masterProduct:id,Product_Code,Product_Name,Product_Price,Product_Stock,Weight_Kg,Length_Cm,Width_Cm,Height_Cm,Volume_Cbm',
            ])
            ->where('Request_Type', 'approved_update')
            ->whereNotNull('Products_Id')
            ->when($status === 'open', function ($qq) {
                $qq->whereIn('Status', ['pending', 'requested', 'under_review', 'needs_changes']);
            })
            ->when($status !== 'open' && $status !== 'all', function ($qq) use ($status) {
                $qq->where('Status', $status);
            })
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($w) use ($search) {
                    $w->where('Comment', 'like', "%{$search}%")
                        ->orWhereHas('vendor', function ($v) use ($search) {
                            $v->where('Vendor_Name', 'like', "%{$search}%")
                                ->orWhere('Vendor_Code', 'like', "%{$search}%");
                        })
                        ->orWhereHas('masterProduct', function ($p) use ($search) {
                            $p->where('Product_Name', 'like', "%{$search}%")
                                ->orWhere('Product_Code', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('Action_At')
            ->orderByDesc('id');

        $page = $q->paginate($perPage);
        $page->getCollection()->transform(function ($row) {
            $changes = $row->Requested_Changes_Json ?? [];
            $row->Requested_Specifications_Display = is_array($changes) && !empty($changes['specifications'])
                ? $this->describeSpecificationChanges((array) $changes['specifications'])
                : [];

            return $row;
        });

        return response()->json($page);
    }

    public function showApprovedUpdateRequest(int $requestId)
    {
        if (! $this->productUpdateRequestColumnsReady()) {
            return response()->json([
                'message' => 'Vendor product update request columns are not migrated yet.',
            ], 409);
        }

        $row = ProductVendorRequest::query()
            ->with([
                'vendor:id,Vendor_Code,Vendor_Name,Trade_Name',
                'masterProduct',
            ])
            ->where('Request_Type', 'approved_update')
            ->whereNotNull('Products_Id')
            ->findOrFail($requestId);

        $changes = is_array($row->Requested_Changes_Json)
            ? $row->Requested_Changes_Json
            : [];

        $product = $row->masterProduct;

        $row->Requested_Change_Details = $this->describeApprovedUpdateFieldChanges($changes, $product);
        $row->Requested_Specifications_Display = !empty($changes['specifications'])
            ? $this->describeSpecificationChanges((array) $changes['specifications'], $product?->id)
            : [];
        $row->Image_Update_Summary = $this->describeImageUpdateSummary(
            (array) ($changes['image_updates'] ?? []),
            $product?->id
        );

        return response()->json(['data' => $row]);
    }

    public function approveProductUpdate(Request $request, int $requestId)
    {
        if (! $this->productUpdateRequestColumnsReady()) {
            return response()->json([
                'message' => 'Vendor product update request columns are not migrated yet.',
            ], 409);
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $allowed = [
            'Product_Department_Id',
            'Product_Sub_Department_Id',
            'Product_Sub_Sub_Department_Id',
            'Product_Type_Id',
            'Product_Brand_Id',
            'Product_Manufacture_Id',
            'Product_Name',
            'Product_Name_Ar',
            'Product_Description',
            'Product_Price',
            'Product_Cost',
            'Product_Stock',
            'Weight_Kg',
            'Length_Cm',
            'Width_Cm',
            'Height_Cm',
            'Volume_Cbm',
            'volume_type',
        ];

        $row = ProductVendorRequest::query()
            ->where('Request_Type', 'approved_update')
            ->whereIn('Status', ['pending', 'requested', 'under_review', 'needs_changes'])
            ->findOrFail($requestId);

        $payload = $row->Requested_Changes_Json ?? [];
        $changes = collect($payload)
            ->only($allowed)
            ->filter(fn ($value, $key) => Schema::hasColumn('Products_Master_T', $key))
            ->all();

        $specChanges = (array) ($payload['specifications'] ?? []);
        $imageUpdates = (array) ($payload['image_updates'] ?? []);

        if (empty($changes) && empty($specChanges) && empty($imageUpdates)) {
            return response()->json(['message' => 'No approved update changes were supplied.'], 422);
        }

        DB::transaction(function () use ($row, $changes, $specChanges, $imageUpdates, $data) {
            $product = ProductMaster::query()
                ->where('id', $row->Products_Id)
                ->where('Vendor_Id', $row->Vendor_Id)
                ->firstOrFail();

            if (!empty($changes)) {
                $product->update($changes);
            }

            if (!empty($specChanges)) {
                $this->syncProductSpecifications($product, $specChanges);
            }

            if (!empty($imageUpdates)) {
                $this->applyProductImageUpdates($product, $imageUpdates);
            }

            $row->update([
                'Status' => 'approved',
                'Comment' => $data['note'] ?? $row->Comment,
                'Action_By_User_Id' => Auth::id(),
                'Action_By_Role' => 'admin',
                'Action_At' => now(),
            ]);
        });

        return response()->json(['message' => 'Vendor product update approved and applied.']);
    }

    private function applyProductImageUpdates(ProductMaster $product, array $imageUpdates): void
    {
        $removeImageIds = collect($imageUpdates['remove_image_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($removeImageIds->isNotEmpty()) {
            $images = ProductImages::query()
                ->where('Products_Id', $product->id)
                ->whereIn('id', $removeImageIds)
                ->get();

            foreach ($images as $image) {
                if ($image->Image_Path) {
                    Storage::disk('r2')->delete($image->Image_Path);
                }

                $image->delete();
            }
        }

        foreach ((array) ($imageUpdates['new_images'] ?? []) as $image) {
            if (empty($image['Image_Path'])) {
                continue;
            }

            ProductImages::create([
                'Product_Image_Code' => CodeGenerator::createCode('PIMG', 'Products_Images_T', 'Product_Image_Code'),
                'Products_Id' => $product->id,
                'Image_Path' => $image['Image_Path'],
                'Image_Size' => $image['Image_Size'] ?? null,
                'Image_Extension' => $image['Image_Extension'] ?? null,
                'Image_Type' => $image['Image_Type'] ?? null,
                'Created_By' => Auth::id(),
                'Created_Date' => now(),
            ]);
        }
    }

    public function rejectProductUpdate(Request $request, int $requestId)
    {
        if (! $this->productUpdateRequestColumnsReady()) {
            return response()->json([
                'message' => 'Vendor product update request columns are not migrated yet.',
            ], 409);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $row = ProductVendorRequest::query()
            ->where('Request_Type', 'approved_update')
            ->whereIn('Status', ['pending', 'requested', 'under_review', 'needs_changes'])
            ->findOrFail($requestId);

        $row->update([
            'Status' => 'rejected',
            'Comment' => $data['reason'],
            'Action_By_User_Id' => Auth::id(),
            'Action_By_Role' => 'admin',
            'Action_At' => now(),
        ]);

        return response()->json(['message' => 'Vendor product update rejected.']);
    }

    private function productUpdateRequestColumnsReady(): bool
    {
        return Schema::hasColumn('Products_Vendor_Requests_T', 'Request_Type')
            && Schema::hasColumn('Products_Vendor_Requests_T', 'Requested_Changes_Json');
    }

    private function describeApprovedUpdateFieldChanges(array $changes, ?ProductMaster $product): array
    {
        $labels = $this->approvedUpdateFieldLabels();

        return collect($changes)
            ->reject(fn ($value, $key) => in_array($key, ['specifications', 'image_updates'], true))
            ->map(function ($requestedValue, $key) use ($labels, $product) {
                $currentValue = $product ? data_get($product->getAttributes(), $key) : null;

                return [
                    'key' => $key,
                    'label' => $labels[$key] ?? Str::headline((string) $key),
                    'current_value' => $currentValue,
                    'requested_value' => $requestedValue,
                ];
            })
            ->values()
            ->all();
    }

    private function approvedUpdateFieldLabels(): array
    {
        return [
            'Product_Department_Id' => 'Department',
            'Product_Sub_Department_Id' => 'Sub Department',
            'Product_Sub_Sub_Department_Id' => 'Sub-Sub Department',
            'Product_Type_Id' => 'Type',
            'Product_Brand_Id' => 'Brand',
            'Product_Manufacture_Id' => 'Manufacture',
            'Product_Name' => 'Product Name',
            'Product_Name_Ar' => 'Arabic Product Name',
            'Product_Description' => 'Description',
            'Product_Price' => 'Price',
            'Product_Cost' => 'Cost',
            'Product_Stock' => 'Stock',
            'Weight_Kg' => 'Weight',
            'Length_Cm' => 'Length',
            'Width_Cm' => 'Width',
            'Height_Cm' => 'Height',
            'Volume_Cbm' => 'Volume',
            'volume_type' => 'Dimension Unit',
        ];
    }

    private function describeImageUpdateSummary(array $imageUpdates, ?int $productId = null): array
    {
        $newImages = collect($imageUpdates['new_images'] ?? [])
            ->filter(fn ($image) => is_array($image))
            ->values();

        $removeImageIds = collect($imageUpdates['remove_image_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $removedImages = collect();
        if ($productId && $removeImageIds->isNotEmpty()) {
            $removedImages = ProductImages::query()
                ->where('Products_Id', $productId)
                ->whereIn('id', $removeImageIds)
                ->get(['id', 'Image_Path', 'Image_Type', 'Image_Size', 'Image_Extension'])
                ->values();
        }

        return [
            'added_count' => $newImages->count(),
            'removed_count' => $removeImageIds->count(),
            'new_images' => $newImages->all(),
            'remove_image_ids' => $removeImageIds->all(),
            'removed_images' => $removedImages->all(),
        ];
    }

    private function syncProductSpecifications(ProductMaster $product, array $specs): void
    {
        $normalized = $this->validateSpecificationChanges(
            $specs,
            (int) $product->Product_Sub_Sub_Department_Id
        );

        foreach ($normalized as $spec) {
            ProductSpecificationProduct::updateOrCreate(
                [
                    'Product_Id' => $product->id,
                    'Product_Specification_Description_Id' => $spec['description_id'],
                ],
                [
                    'product_specification_value_id' => $spec['value_id'],
                    'Created_By' => Auth::id(),
                ]
            );
        }
    }

    private function validateSpecificationChanges(array $specs, int $subSubDeptId): array
    {
        if (empty($specs)) {
            return [];
        }

        $allowedDescIds = ProductSpecificationDescription::query()
            ->where('product_sub_sub_department_id', $subSubDeptId)
            ->pluck('id')
            ->all();

        $allowedDescSet = array_flip($allowedDescIds);
        $validated = [];

        foreach ($specs as $i => $spec) {
            $descId = (int) ($spec['description_id'] ?? $spec['product_specification_description_id'] ?? 0);
            $valueId = (int) ($spec['value_id'] ?? $spec['product_specification_value_id'] ?? 0);

            if (!$descId || !$valueId) {
                abort(422, "Invalid specification update at index {$i}.");
            }

            if (!isset($allowedDescSet[$descId])) {
                abort(422, "Specification description {$descId} does not belong to this product category.");
            }

            $valueOk = ProductSpecificationValue::query()
                ->where('id', $valueId)
                ->where('product_specification_description_id', $descId)
                ->exists();

            if (!$valueOk) {
                abort(422, "Specification value {$valueId} is not valid for description {$descId}.");
            }

            $validated[$descId] = [
                'description_id' => $descId,
                'value_id' => $valueId,
            ];
        }

        return array_values($validated);
    }

    private function describeSpecificationChanges(array $specs, ?int $productId = null): array
    {
        $descIds = collect($specs)->map(fn ($s) => (int) ($s['description_id'] ?? $s['product_specification_description_id'] ?? 0))->filter()->unique()->values();
        $valueIds = collect($specs)->map(fn ($s) => (int) ($s['value_id'] ?? $s['product_specification_value_id'] ?? 0))->filter()->unique()->values();

        $descriptions = $descIds->isEmpty()
            ? collect()
            : ProductSpecificationDescription::query()
                ->whereIn('id', $descIds)
                ->pluck('Product_Specification_Description_Name', 'id');

        $values = $valueIds->isEmpty()
            ? collect()
            : ProductSpecificationValue::query()
                ->whereIn('id', $valueIds)
                ->pluck('value', 'id');

        $currentSpecs = collect();
        if ($productId && $descIds->isNotEmpty()) {
            $currentSpecs = ProductSpecificationProduct::query()
                ->with('value:id,value')
                ->where('Product_Id', $productId)
                ->whereIn('Product_Specification_Description_Id', $descIds)
                ->get()
                ->keyBy('Product_Specification_Description_Id');
        }

        return collect($specs)->map(function ($spec) use ($descriptions, $values, $currentSpecs) {
            $descId = (int) ($spec['description_id'] ?? $spec['product_specification_description_id'] ?? 0);
            $valueId = (int) ($spec['value_id'] ?? $spec['product_specification_value_id'] ?? 0);
            $current = $currentSpecs->get($descId);

            return [
                'description_id' => $descId,
                'value_id' => $valueId,
                'description' => $descriptions[$descId] ?? "Spec #{$descId}",
                'value' => $values[$valueId] ?? "Value #{$valueId}",
                'current_value_id' => $current?->product_specification_value_id,
                'current_value' => $current?->value?->value,
            ];
        })->values()->all();
    }

}
