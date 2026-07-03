<?php

namespace App\Http\Controllers;

use App\Models\ProductImages;
use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use Illuminate\Http\Request;
// If you want approve → move to master tables, import your real models:
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Helpers\CodeGenerator;
use App\Models\ProductBrands;
use App\Models\ProductBulkPrice;
use App\Models\ProductDepartments;
use App\Models\ProductManufacture;
use App\Models\ProductSpecificationProduct;
use App\Models\ProductSpecificationDescription;
use App\Models\ProductSpecificationValue;
use App\Models\ProductSubDepartment;
use App\Models\ProductSubSubDepartment;
use App\Models\ProductTemporaryBulkPrice;
use App\Models\ProductTypes;
use App\Models\ProductVendorRequest;
use App\Support\Pricing\BulkPriceRules;
use App\Support\Vendors\VendorApprovalSla;

class AdminTempProductController extends Controller
{
    /**
     * Open (still actionable) statuses for vendor product update requests.
     */
    private const OPEN_UPDATE_REQUEST_STATUSES = ['pending', 'requested', 'under_review', 'needs_changes'];

    /**
     * Memoized allowed-fields list filtered to columns that actually exist
     * on Products_Master_T (avoids one Schema query per row in list loops).
     */
    private ?array $approvedUpdateExistingFieldsCache = null;

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
private function approveOne(ProductTemporary $temp, ?string $commissionType = null, ?float $commissionValue = null): int
{
    // Make sure images relation is loaded (safe even if already eager-loaded)
    $temp->loadMissing(['images', 'specs']);

    // If already approved, just return existing Approved_Product_Id (if present)
    if ($temp->Submission_Status === 'approved' && $temp->Approved_Product_Id) {
        return $temp->Approved_Product_Id;
    }

    $approvedProductId = 0;

    DB::transaction(function () use ($temp, $commissionType, $commissionValue, &$approvedProductId) {

        // 1) Generate Product_Code like admin store()
        $productMasterCode = CodeGenerator::createCode('PROD', 'Products_Master_T', 'Product_Code');

        // 2) Create product in master
        $masterData = [
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
        ];

        // Per-product commission chosen by the admin at approval time
        if ($commissionType !== null && Schema::hasColumn('Products_Master_T', 'Commission_Type')) {
            $masterData['Commission_Type'] = $commissionType;
            $masterData['Commission_Value'] = $commissionValue;
        }

        // Carry the vendor-declared cost over to master (it used to be
        // silently dropped). Minimum_Selling_Price intentionally stays NULL:
        // no price floor applies until an admin sets one.
        if (Schema::hasColumn('Products_Master_T', 'Product_Cost')) {
            $masterData['Product_Cost'] = $temp->Product_Cost;
        }

        $master = ProductMaster::create($masterData);

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

        // 4b) Copy vendor-requested bulk price tiers -> Products_Bulk_Prices_T
        // (guarded: pre-migration environments simply skip). The set is
        // re-validated defensively — an invalid set is skipped WITH a log
        // rather than failing the whole approval.
        $this->copyTempBulkPrices($temp, $master);

        // 5) Update temp status
        $tempUpdate = [
            'Submission_Status'   => 'approved',
            'Reviewed_By'         => Auth::id(),
            'Reviewed_At'         => now(),
            'Approved_Product_Id' => $master->id,
            'Rejection_Reason'    => null,
        ];

        // Keep the chosen commission on the temp row too (audit trail)
        if ($commissionType !== null && Schema::hasColumn('Products_Temporary_T', 'Commission_Type')) {
            $tempUpdate['Commission_Type'] = $commissionType;
            $tempUpdate['Commission_Value'] = $commissionValue;
        }

        $temp->update($tempUpdate);

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


        $with = [
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
        ];

        // Vendor-requested quantity-tier bulk prices (guarded for the deploy
        // window before the bulk-prices migration has run).
        if (Schema::hasTable('Products_Temporary_Bulk_Prices_T')) {
            $with[] = 'bulkPrices';
        }

        $product = ProductTemporary::withTrashed()
        ->with($with)
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
     * Validate the required per-product commission payload.
     * percent: > 0 and <= 100. fixed: > 0 (per-unit amount in OMR).
     *
     * Returns ['commission_type' => string, 'commission_value' => float].
     */
    private function validateCommissionInput(Request $request): array
    {
        $rules = [
            'commission_type'  => ['required', Rule::in(['percent', 'fixed'])],
            'commission_value' => ['required', 'numeric', 'gt:0'],
        ];

        if ($request->input('commission_type') === 'percent') {
            $rules['commission_value'][] = 'max:100';
        }

        $data = $request->validate($rules);

        return [
            'commission_type'  => $data['commission_type'],
            'commission_value' => round((float) $data['commission_value'], 3),
        ];
    }

    /**
     * 5) Approve: mark approved + (optionally) copy to Products_Master_T + Products_Images_T.
     * Requires the per-product commission (written to temp + master).
     */
    public function approve(Request $request, int $tempId)
    {
        // Validate outside try/catch so validation errors return 422, not 500.
        $commission = $this->validateCommissionInput($request);

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

            // Already approved: approveOne() would return the existing master id
            // WITHOUT applying the supplied commission — say so instead of
            // reporting a success the admin would misread as "commission set".
            if ($temp->Submission_Status === 'approved' && $temp->Approved_Product_Id) {
                return response()->json([
                    'message'             => 'This product was already approved; the supplied commission was NOT applied. Edit the commission from the vendor products page.',
                    'approved_product_id' => $temp->Approved_Product_Id,
                ], 409);
            }

            $approvedProductId = $this->approveOne(
                $temp,
                $commission['commission_type'],
                $commission['commission_value']
            );

            return response()->json([
                'message'             => 'Approved successfully.',
                'approved_product_id' => $approvedProductId,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Approval failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Set / edit the commission on an existing vendor-owned master product.
     * Affects FUTURE orders only.
     */
    public function setVendorProductCommission(Request $request, int $productId)
    {
        $commission = $this->validateCommissionInput($request);

        // Same deploy-window guard as approveOne(): the entrypoints never run
        // artisan migrate, so an unmigrated environment must get a graceful
        // message instead of a raw SQL 500.
        if (! Schema::hasColumn('Products_Master_T', 'Commission_Type')) {
            return response()->json([
                'success' => false,
                'message' => 'Product commission columns are not migrated yet.',
            ], 409);
        }

        $product = ProductMaster::query()->find($productId);

        if (! $product || is_null($product->Vendor_Id)) {
            return response()->json([
                'success' => false,
                'message' => 'Not a vendor product.',
            ], 422);
        }

        $product->update([
            'Commission_Type'  => $commission['commission_type'],
            'Commission_Value' => $commission['commission_value'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product commission updated. Applies to future orders only.',
            'data'    => $product,
        ]);
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
        $rules = [
            'ids'   => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            // ONE commission applied to ALL selected temp products
            'commission_type'  => ['required', Rule::in(['percent', 'fixed'])],
            'commission_value' => ['required', 'numeric', 'gt:0'],
        ];

        if ($request->input('commission_type') === 'percent') {
            $rules['commission_value'][] = 'max:100';
        }

        $data = $request->validate($rules);

        $commissionType  = $data['commission_type'];
        $commissionValue = round((float) $data['commission_value'], 3);

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

            // Already approved: approveOne() would silently keep the existing
            // master product (and its existing/NULL commission), so counting it
            // as approved would make the admin believe the new commission is in
            // force. Report it as skipped instead.
            if ($temp->Submission_Status === 'approved' && $temp->Approved_Product_Id) {
                $failed[] = ['id' => $id, 'error' => 'Already approved; commission NOT applied. Edit it from the vendor products page.'];
                continue;
            }

            try {
                $this->approveOne($temp, $commissionType, $commissionValue);
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
            'ids'    => ['required', 'array', 'min:1', 'max:200'],
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

        // Widened to every column the allowed-update list can change so the
        // diff display can show the CURRENT master value for each field.
        $masterSelect = array_values(array_unique(array_merge(
            ['id', 'Product_Code', 'Product_Name', 'Product_Price', 'Product_Stock', 'Weight_Kg', 'Length_Cm', 'Width_Cm', 'Height_Cm', 'Volume_Cbm'],
            $this->approvedUpdateExistingFields()
        )));

        $q = ProductVendorRequest::query()
            ->with([
                'vendor:id,Vendor_Code,Vendor_Name,Trade_Name',
                'masterProduct' => fn ($p) => $p->select($masterSelect),
            ])
            ->where('Request_Type', 'approved_update')
            ->whereNotNull('Products_Id')
            ->when($status === 'open', function ($qq) {
                $qq->whereIn('Status', self::OPEN_UPDATE_REQUEST_STATUSES);
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

        // Batch FK id -> name lookups across the whole page (one whereIn per
        // FK table) instead of per-row queries inside the transform loop.
        $fkNames = $this->resolveApprovedUpdateFkNames($page->getCollection());

        $page->getCollection()->transform(function ($row) use ($fkNames) {
            $changes = is_array($row->Requested_Changes_Json) ? $row->Requested_Changes_Json : [];

            $row->Requested_Changes_Display = $this->describeApprovedUpdateFieldChanges(
                $changes,
                $row->masterProduct,
                $fkNames
            );
            $row->Requested_Specifications_Display = !empty($changes['specifications'])
                ? $this->describeSpecificationChanges((array) $changes['specifications'])
                : [];
            $row->Requested_Images_Display = $this->describeImageUpdateCounts(
                (array) ($changes['image_updates'] ?? [])
            );
            $row->Requested_Bulk_Prices_Display = (array_key_exists('bulk_prices', $changes) && is_array($changes['bulk_prices']))
                ? $this->describeBulkPriceChanges($changes['bulk_prices'], $row->masterProduct?->id)
                : null;

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

        $fkNames = $this->resolveApprovedUpdateFkNames(collect([$row]));
        $fieldChanges = $this->describeApprovedUpdateFieldChanges($changes, $product, $fkNames);

        $row->Requested_Change_Details = $fieldChanges;
        // Same shape as the list endpoint so the UI can share one renderer.
        $row->Requested_Changes_Display = $fieldChanges;
        $row->Requested_Specifications_Display = !empty($changes['specifications'])
            ? $this->describeSpecificationChanges((array) $changes['specifications'], $product?->id)
            : [];
        $row->Image_Update_Summary = $this->describeImageUpdateSummary(
            (array) ($changes['image_updates'] ?? []),
            $product?->id
        );
        $row->Requested_Images_Display = $this->describeImageUpdateCounts(
            (array) ($changes['image_updates'] ?? [])
        );
        $row->Requested_Bulk_Prices_Display = (array_key_exists('bulk_prices', $changes) && is_array($changes['bulk_prices']))
            ? $this->describeBulkPriceChanges($changes['bulk_prices'], $product?->id)
            : null;

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

        $row = ProductVendorRequest::query()
            ->where('Request_Type', 'approved_update')
            ->whereIn('Status', self::OPEN_UPDATE_REQUEST_STATUSES)
            ->findOrFail($requestId);

        try {
            $this->applyProductUpdateRequest($row, $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Vendor product update approved and applied.']);
    }

    /**
     * Bulk-approve vendor product update requests.
     *
     * POST /api/admin/product-update-requests/bulk/approve  {ids: [..]}
     * Response shape mirrors the products-temp bulkApprove pattern:
     * {message, approved_ids: [...], failed: [{id, error}]}.
     */
    public function bulkApproveProductUpdates(Request $request)
    {
        if (! $this->productUpdateRequestColumnsReady()) {
            return response()->json([
                'message' => 'Vendor product update request columns are not migrated yet.',
            ], 409);
        }

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $ids = $data['ids'];

        $approved = [];
        $failed   = [];

        $rows = ProductVendorRequest::query()
            ->where('Request_Type', 'approved_update')
            ->whereNotNull('Products_Id')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $row = $rows->get($id);

            if (! $row) {
                $failed[] = ['id' => $id, 'error' => 'Update request not found.'];
                continue;
            }

            if (! in_array($row->Status, self::OPEN_UPDATE_REQUEST_STATUSES, true)) {
                $failed[] = ['id' => $id, 'error' => "Request is not open (status: {$row->Status})."];
                continue;
            }

            try {
                // Same code path as the single approve so behavior cannot drift.
                $this->applyProductUpdateRequest($row, null);
                $approved[] = $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'message'      => 'Bulk approve finished.',
            'approved_ids' => $approved,
            'failed'       => $failed,
        ]);
    }

    /**
     * Core "apply a vendor product update request" logic shared by the single
     * and bulk approve endpoints: allowed-list + Schema::hasColumn filtering,
     * Commission_* strip, spec sync, image updates, one DB transaction per
     * request, then status -> approved with audit fields.
     *
     * @throws \InvalidArgumentException when the request carries no applicable changes
     * @throws \Throwable
     */
    private function applyProductUpdateRequest(ProductVendorRequest $row, ?string $note): void
    {
        $payload = is_array($row->Requested_Changes_Json) ? $row->Requested_Changes_Json : [];

        $changes = collect($payload)
            ->only($this->approvedUpdateExistingFields())
            ->all();

        // Defense-in-depth: vendor-requested changes must NEVER touch the
        // admin-controlled per-product commission settings.
        unset($changes['Commission_Type'], $changes['Commission_Value']);

        $specChanges = (array) ($payload['specifications'] ?? []);
        $imageUpdates = (array) ($payload['image_updates'] ?? []);

        // Quantity-tier bulk prices: key PRESENT (even as an empty array =
        // clear all tiers) means a replace-set change; key ABSENT means the
        // request does not touch tiers at all.
        $hasBulkPrices = array_key_exists('bulk_prices', $payload) && is_array($payload['bulk_prices']);
        $bulkPrices = $hasBulkPrices ? array_values($payload['bulk_prices']) : null;

        if (empty($changes) && empty($specChanges) && empty($imageUpdates) && ! $hasBulkPrices) {
            throw new \InvalidArgumentException('No approved update changes were supplied.');
        }

        DB::transaction(function () use ($row, $changes, $specChanges, $imageUpdates, $hasBulkPrices, $bulkPrices, $note) {
            $product = ProductMaster::query()
                ->where('id', $row->Products_Id)
                ->where('Vendor_Id', $row->Vendor_Id)
                ->firstOrFail();

            // Price floor: a vendor-requested price may never go below the
            // admin-set Minimum_Selling_Price. (Vendors cannot change the
            // floor itself — it is not in the allowed-fields list.)
            if (array_key_exists('Product_Price', $changes)
                && $product->Minimum_Selling_Price !== null
                && $changes['Product_Price'] !== null && $changes['Product_Price'] !== ''
                && (float) $changes['Product_Price'] < (float) $product->Minimum_Selling_Price) {
                throw new \InvalidArgumentException(
                    ProductMasterController::priceFloorMessage($product->Minimum_Selling_Price)
                );
            }

            if (!empty($changes)) {
                $product->update($changes);
            }

            if (!empty($specChanges)) {
                $this->syncProductSpecifications($product, $specChanges);
            }

            if (!empty($imageUpdates)) {
                $this->applyProductImageUpdates($product, $imageUpdates);
            }

            if ($hasBulkPrices) {
                $this->applyBulkPriceChanges($product, $bulkPrices);
            }

            $row->update([
                'Status' => 'approved',
                'Comment' => $note ?? $row->Comment,
                'Action_By_User_Id' => Auth::id(),
                'Action_By_Role' => 'admin',
                'Action_At' => now(),
            ]);
        });
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

    /**
     * approveOne step 4b: copy the temp product's quantity-tier bulk prices
     * to the freshly created master product.
     *
     * Both tables are hasTable-guarded (deploy window before the bulk-prices
     * migration). The set is re-validated against the master's floor (NULL on
     * fresh vendor approvals — see the Minimum_Selling_Price note above);
     * an invalid set is skipped with a warning log instead of failing the
     * approval.
     */
    private function copyTempBulkPrices(ProductTemporary $temp, ProductMaster $master): void
    {
        if (! Schema::hasTable('Products_Bulk_Prices_T')
            || ! Schema::hasTable('Products_Temporary_Bulk_Prices_T')) {
            return;
        }

        $tempTiers = ProductTemporaryBulkPrice::query()
            ->where('Products_Temporary_Id', $temp->id)
            ->orderBy('Min_Qty')
            ->get();

        if ($tempTiers->isEmpty()) {
            return;
        }

        $tiers = $tempTiers->map(fn ($tier) => [
            'min_qty'    => $tier->Min_Qty,
            'max_qty'    => $tier->Max_Qty,
            'unit_price' => $tier->Unit_Price,
        ])->all();

        $floor = $master->Minimum_Selling_Price !== null
            ? (float) $master->Minimum_Selling_Price
            : null;

        $errors = BulkPriceRules::validateSet($tiers, $floor);

        if (! empty($errors)) {
            Log::warning('Skipped copying invalid bulk price tiers during temp product approval.', [
                'temp_product_id' => $temp->id,
                'master_product_id' => $master->id,
                'errors' => $errors,
            ]);

            return;
        }

        foreach ($tiers as $tier) {
            ProductBulkPrice::create([
                'Products_Id' => $master->id,
                'Min_Qty'     => (int) $tier['min_qty'],
                'Max_Qty'     => $tier['max_qty'] !== null ? (int) $tier['max_qty'] : null,
                'Unit_Price'  => round((float) $tier['unit_price'], 3),
                'Created_By'  => Auth::id(),
            ]);
        }
    }

    /**
     * Replace-set the master product's bulk price tiers from an approved
     * vendor update request ('bulk_prices' key). Validated against the smart
     * rules + the product's Minimum_Selling_Price floor; a failure throws
     * InvalidArgumentException so the single approve returns 422 and the bulk
     * approve reports it in failed[].
     */
    private function applyBulkPriceChanges(ProductMaster $product, array $tiers): void
    {
        if (! Schema::hasTable('Products_Bulk_Prices_T')) {
            throw new \InvalidArgumentException('Bulk price table is not migrated yet.');
        }

        $floor = $product->Minimum_Selling_Price !== null
            ? (float) $product->Minimum_Selling_Price
            : null;

        $errors = BulkPriceRules::validateSet($tiers, $floor);

        if (! empty($errors)) {
            throw new \InvalidArgumentException('Bulk prices: ' . implode(' ', $errors));
        }

        ProductBulkPrice::query()->where('Products_Id', $product->id)->delete();

        foreach (array_values($tiers) as $tier) {
            $min = $tier['min_qty'] ?? $tier['Min_Qty'] ?? null;
            $max = $tier['max_qty'] ?? $tier['Max_Qty'] ?? null;
            $price = $tier['unit_price'] ?? $tier['Unit_Price'] ?? null;

            ProductBulkPrice::create([
                'Products_Id' => $product->id,
                'Min_Qty'     => (int) $min,
                'Max_Qty'     => ($max === null || $max === '') ? null : (int) $max,
                'Unit_Price'  => round((float) $price, 3),
                'Created_By'  => Auth::id(),
            ]);
        }
    }

    /**
     * {current, requested} tier tables for the update-request diff display.
     * Both lists are sorted by min_qty; each tier carries a human 'label'
     * ("5-10" / "51+"). Requested values come straight from the JSON payload
     * (NOT validated here — display only).
     */
    private function describeBulkPriceChanges(array $requested, ?int $productId): array
    {
        $current = [];

        if ($productId && Schema::hasTable('Products_Bulk_Prices_T')) {
            $current = ProductBulkPrice::query()
                ->where('Products_Id', $productId)
                ->orderBy('Min_Qty')
                ->get()
                ->map(fn ($tier) => [
                    'min_qty'    => (int) $tier->Min_Qty,
                    'max_qty'    => $tier->Max_Qty !== null ? (int) $tier->Max_Qty : null,
                    'unit_price' => (float) $tier->Unit_Price,
                    'label'      => BulkPriceRules::rangeLabel(
                        (int) $tier->Min_Qty,
                        $tier->Max_Qty !== null ? (int) $tier->Max_Qty : null
                    ),
                ])
                ->values()
                ->all();
        }

        $requestedTiers = collect($requested)
            ->filter(fn ($tier) => is_array($tier))
            ->map(function ($tier) {
                $min = (int) ($tier['min_qty'] ?? $tier['Min_Qty'] ?? 0);
                $maxRaw = $tier['max_qty'] ?? $tier['Max_Qty'] ?? null;
                $max = ($maxRaw === null || $maxRaw === '') ? null : (int) $maxRaw;

                return [
                    'min_qty'    => $min,
                    'max_qty'    => $max,
                    'unit_price' => (float) ($tier['unit_price'] ?? $tier['Unit_Price'] ?? 0),
                    'label'      => BulkPriceRules::rangeLabel($min, $max),
                ];
            })
            ->sortBy('min_qty')
            ->values()
            ->all();

        return [
            'current'   => $current,
            'requested' => $requestedTiers,
        ];
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
            ->whereIn('Status', self::OPEN_UPDATE_REQUEST_STATUSES)
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

    /**
     * Full allowed-fields list a vendor "approved_update" request may change.
     * Commission_Type / Commission_Value are admin-only and must never be here.
     */
    private function approvedUpdateAllowedFields(): array
    {
        return [
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
    }

    /**
     * Allowed fields that actually exist as Products_Master_T columns
     * (memoized: one getColumnListing query per request, not per row).
     */
    private function approvedUpdateExistingFields(): array
    {
        return $this->approvedUpdateExistingFieldsCache ??= array_values(array_intersect(
            $this->approvedUpdateAllowedFields(),
            Schema::getColumnListing('Products_Master_T')
        ));
    }

    /**
     * FK id fields on an update request and where to resolve their display names.
     * Format: field => [model class, name column].
     */
    private function approvedUpdateFkNameSources(): array
    {
        return [
            'Product_Department_Id'         => [ProductDepartments::class, 'Product_Department_Name'],
            'Product_Sub_Department_Id'     => [ProductSubDepartment::class, 'Sub_Department_Name'],
            'Product_Sub_Sub_Department_Id' => [ProductSubSubDepartment::class, 'Product_Sub_Sub_Department_Name'],
            'Product_Type_Id'               => [ProductTypes::class, 'Product_Types_Name'],
            'Product_Brand_Id'              => [ProductBrands::class, 'Products_Brands_Name'],
            'Product_Manufacture_Id'        => [ProductManufacture::class, 'Products_Manufacture_Name'],
        ];
    }

    /**
     * Batch-resolve FK ids (both current master values and requested values)
     * to display names for a set of update-request rows.
     *
     * Returns: [field => [id => name]] — one whereIn query per FK table.
     *
     * @param \Illuminate\Support\Collection $rows collection of ProductVendorRequest (masterProduct loaded)
     */
    private function resolveApprovedUpdateFkNames($rows): array
    {
        $sources = $this->approvedUpdateFkNameSources();
        $idsByField = [];

        foreach ($rows as $row) {
            $changes = is_array($row->Requested_Changes_Json) ? $row->Requested_Changes_Json : [];
            $product = $row->masterProduct;

            foreach (array_keys($sources) as $field) {
                $requested = $changes[$field] ?? null;
                if (is_numeric($requested) && (int) $requested > 0) {
                    $idsByField[$field][] = (int) $requested;
                }

                $current = $product?->getAttribute($field);
                if (is_numeric($current) && (int) $current > 0) {
                    $idsByField[$field][] = (int) $current;
                }
            }
        }

        $names = [];
        foreach ($sources as $field => [$model, $nameColumn]) {
            $ids = array_values(array_unique($idsByField[$field] ?? []));

            $names[$field] = empty($ids)
                ? []
                : $model::query()->whereIn('id', $ids)->pluck($nameColumn, 'id')->all();
        }

        return $names;
    }

    /**
     * Cheap {added, removed} counts for the image_updates part of a request
     * (server-side mirror of the client summary — no DB lookups).
     */
    private function describeImageUpdateCounts(array $imageUpdates): array
    {
        $added = collect($imageUpdates['new_images'] ?? [])
            ->filter(fn ($image) => is_array($image))
            ->count();

        $removed = collect($imageUpdates['remove_image_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->count();

        return [
            'added'   => $added,
            'removed' => $removed,
        ];
    }

    /**
     * Field-by-field diff of an update request against the current master row.
     *
     * Only keys in the allowed-update list (and existing as real columns) are
     * shown — Commission_Type/Commission_Value can NEVER appear. FK id fields
     * are resolved to display names when $fkNames has them (see
     * resolveApprovedUpdateFkNames); otherwise the raw id is shown.
     *
     * Each entry: {key, label, current, requested} plus legacy
     * current_value/requested_value aliases (raw values) kept for the
     * existing detail page.
     */
    private function describeApprovedUpdateFieldChanges(array $changes, ?ProductMaster $product, array $fkNames = []): array
    {
        $labels = $this->approvedUpdateFieldLabels();

        return collect($changes)
            ->only($this->approvedUpdateExistingFields())
            ->except(['Commission_Type', 'Commission_Value'])
            ->map(function ($requestedValue, $key) use ($labels, $product, $fkNames) {
                $currentValue = $product ? data_get($product->getAttributes(), $key) : null;

                $current = $currentValue;
                $requested = $requestedValue;

                if (isset($fkNames[$key])) {
                    $current = $fkNames[$key][(int) $currentValue] ?? $currentValue;
                    $requested = $fkNames[$key][(int) $requestedValue] ?? $requestedValue;
                }

                return [
                    'key' => $key,
                    'label' => $labels[$key] ?? Str::headline((string) $key),
                    'current' => $current,
                    'requested' => $requested,
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
