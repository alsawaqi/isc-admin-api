<?php

namespace App\Http\Controllers;

use App\Helpers\CodeGenerator;
use App\Models\ProductDiscount;
use App\Models\ProductMaster;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductDiscountController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $targetType = $request->query('target_type');
        $active = $request->query('active');
        $perPage = max(5, min((int) $request->query('per_page', 15), 50));

        $query = ProductDiscount::query()
            ->with([
                'product:id,Product_Code,Product_Name,Product_Price',
                'department:id,Product_Department_Name',
                'subDepartment:id,Sub_Department_Name,Products_Departments_Id',
                'subSubDepartment:id,Product_Sub_Sub_Department_Name,Product_Sub_Department_Id',
            ])
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('Product_Discount_Name', 'like', "%{$search}%")
                    ->orWhere('Product_Discount_Code', 'like', "%{$search}%");
            });
        }

        if ($targetType) {
            $query->where('Target_Type', $targetType);
        }

        if ($active !== null && $active !== '') {
            $query->where('Product_Discount_Is_Active', (bool) (int) $active);
        }

        return response()->json(
            $query->paginate($perPage)->through(fn (ProductDiscount $discount) => $this->formatDiscount($discount))
        );
    }

    /**
     * Product picker for the bulk-discount builder: filter the catalog by the
     * department tree + search, and flag products that already carry a live
     * product-targeted discount (computed in one pass, no N+1).
     */
    public function products(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        // Soft-deleted products are excluded by the model's SoftDeletes scope.
        $query = ProductMaster::query()->orderByDesc('id');

        $filters = [
            'department_id' => 'Product_Department_Id',
            'sub_department_id' => 'Product_Sub_Department_Id',
            'sub_sub_department_id' => 'Product_Sub_Sub_Department_Id',
        ];

        foreach ($filters as $param => $column) {
            $value = $request->query($param);
            if ($value !== null && $value !== '') {
                $query->where($column, (int) $value);
            }
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('Product_Name', 'like', "%{$search}%")
                    ->orWhere('Product_Name_Ar', 'like', "%{$search}%")
                    ->orWhere('Product_Sku', 'like', "%{$search}%")
                    ->orWhere('Product_Code', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        $hasIsActive = Schema::hasColumn('Products_Master_T', 'Is_Active');

        // Live discounts for this page only: product-targeted, published, and
        // whose date window contains "now" (mirrors effectiveStatus()).
        $pageIds = collect($paginator->items())->pluck('id')->all();
        $now = now(config('app.business_timezone', 'Asia/Muscat'))->format('Y-m-d H:i:s');

        $liveDiscounts = collect();
        if ($pageIds !== []) {
            $liveDiscounts = ProductDiscount::query()
                ->where('Target_Type', 'product')
                ->whereIn('Products_Id', $pageIds)
                ->where('Product_Discount_Is_Active', 1)
                ->where(function ($q) use ($now) {
                    $q->whereNull('Start_Date')->orWhere('Start_Date', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('End_Date')->orWhere('End_Date', '>=', $now);
                })
                ->orderByDesc('id')
                ->get(['id', 'Products_Id', 'Product_Discount_Type', 'Product_Discount_Value'])
                ->groupBy('Products_Id');
        }

        return response()->json(
            $paginator->through(function (ProductMaster $product) use ($liveDiscounts, $hasIsActive) {
                $discount = optional($liveDiscounts->get($product->id))->first();

                return [
                    'id' => $product->id,
                    'Product_Code' => $product->Product_Code,
                    'Product_Name' => $product->Product_Name,
                    'Product_Sku' => $product->Product_Sku,
                    'Product_Price' => $product->Product_Price !== null
                        ? round((float) $product->Product_Price, 3)
                        : null,
                    'Product_Stock' => $product->Product_Stock,
                    'Is_Active' => $hasIsActive ? (bool) $product->Is_Active : true,
                    'has_active_discount' => $discount !== null,
                    'active_discount' => $discount ? [
                        'type' => $discount->Product_Discount_Type,
                        'value' => (float) $discount->Product_Discount_Value,
                    ] : null,
                ];
            })
        );
    }

    public function store(Request $request)
    {
        $data = $this->validatedPayload($request);

        $discount = ProductDiscount::create(array_merge($data, [
            'Product_Discount_Code' => CodeGenerator::createCode('PDISC', 'Products_Discounts_T', 'Product_Discount_Code'),
            'Created_By' => Auth::id(),
            'Created_Date' => now(),
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Discount created successfully.',
            'data' => $this->formatDiscount($discount->fresh($this->relations())),
        ], 201);
    }

    /**
     * Apply ONE discount to many products at once. Missing / soft-deleted
     * products and fixed discounts that would wipe out the price are skipped
     * (with a reason); minimum-selling-price breaches still create but emit a
     * non-blocking warning.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['integer', 'distinct'],
            'Product_Discount_Name' => ['required', 'string', 'max:255'],
            'Product_Discount_Type' => ['required', Rule::in(['percentage', 'fixed'])],
            'Product_Discount_Value' => ['required', 'numeric', 'gt:0'],
            'Start_Date' => ['nullable', 'date'],
            'End_Date' => ['nullable', 'date', 'after_or_equal:Start_Date'],
            'Product_Discount_Is_Active' => ['nullable', 'boolean'],
        ]);

        $type = $data['Product_Discount_Type'];
        $value = (float) $data['Product_Discount_Value'];

        if ($type === 'percentage' && $value > 100) {
            throw ValidationException::withMessages([
                'Product_Discount_Value' => 'Percentage discounts cannot be more than 100%.',
            ]);
        }

        $startDate = $this->normalizeLocalDateInput($data['Start_Date'] ?? null);
        $endDate = $this->normalizeLocalDateInput($data['End_Date'] ?? null);
        $isActive = (bool) ($data['Product_Discount_Is_Active'] ?? true);

        $ids = array_values(array_map('intval', $data['product_ids']));

        // One query for every referenced product; SoftDeletes keeps trashed
        // rows out, so they fall through to "not found" below.
        $products = ProductMaster::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $hasMinSelling = Schema::hasColumn('Products_Master_T', 'Minimum_Selling_Price');

        $createdIds = [];
        $skipped = [];
        $warnings = [];

        DB::transaction(function () use (
            $ids,
            $products,
            $data,
            $type,
            $value,
            $startDate,
            $endDate,
            $isActive,
            $hasMinSelling,
            &$createdIds,
            &$skipped,
            &$warnings
        ) {
            foreach ($ids as $id) {
                $product = $products->get($id);

                if (! $product) {
                    $skipped[] = ['id' => $id, 'reason' => 'Product not found or deleted'];
                    continue;
                }

                $price = (float) $product->Product_Price;

                if ($type === 'fixed' && $value >= $price) {
                    $skipped[] = ['id' => $id, 'reason' => 'Fixed discount >= product price'];
                    continue;
                }

                $discount = ProductDiscount::create([
                    'Product_Discount_Code' => CodeGenerator::createCode('PDISC', 'Products_Discounts_T', 'Product_Discount_Code'),
                    'Product_Discount_Name' => $data['Product_Discount_Name'],
                    'Target_Type' => 'product',
                    'Products_Id' => $id,
                    'Product_Department_Id' => null,
                    'Product_Sub_Department_Id' => null,
                    'Product_Sub_Sub_Department_Id' => null,
                    'Product_Discount_Type' => $type,
                    'Product_Discount_Value' => $value,
                    'Start_Date' => $startDate,
                    'End_Date' => $endDate,
                    'Product_Discount_Is_Active' => $isActive,
                    'Created_By' => Auth::id(),
                    'Created_Date' => now(),
                ]);

                $createdIds[] = $discount->id;

                if ($hasMinSelling && $product->Minimum_Selling_Price !== null) {
                    $finalPrice = $type === 'fixed'
                        ? round($price - $value, 3)
                        : round($price * (1 - $value / 100), 3);
                    $minSelling = (float) $product->Minimum_Selling_Price;

                    if ($finalPrice < $minSelling) {
                        $warnings[] = [
                            'product_id' => $id,
                            'warning' => sprintf(
                                'Discounted price %.3f is below minimum selling price %.3f',
                                $finalPrice,
                                $minSelling
                            ),
                        ];
                    }
                }
            }
        });

        $createdCount = count($createdIds);

        return response()->json([
            'success' => true,
            'message' => $createdCount > 0
                ? "Discount applied to {$createdCount} product(s)."
                : 'No discounts were created.',
            'created_count' => $createdCount,
            'created_ids' => $createdIds,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ], $createdCount > 0 ? 201 : 200);
    }

    public function update(Request $request, int $id)
    {
        $discount = ProductDiscount::findOrFail($id);
        $data = $this->validatedPayload($request);

        $discount->update(array_merge($data, [
            'Updated_By' => Auth::id(),
            'Updated_Date' => now(),
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Discount updated successfully.',
            'data' => $this->formatDiscount($discount->fresh($this->relations())),
        ]);
    }

    public function toggle(int $id)
    {
        $discount = ProductDiscount::findOrFail($id);
        $discount->update([
            'Product_Discount_Is_Active' => ! (bool) $discount->Product_Discount_Is_Active,
            'Updated_By' => Auth::id(),
            'Updated_Date' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $discount->Product_Discount_Is_Active ? 'Discount published.' : 'Discount unpublished.',
            'data' => $this->formatDiscount($discount->fresh($this->relations())),
        ]);
    }

    public function destroy(int $id)
    {
        ProductDiscount::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Discount deleted successfully.',
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        // Department-targeted discounts are no longer creatable/editable —
        // discounts target single products only. Legacy department rows are
        // still listed, toggleable, and deletable (index/toggle/destroy do
        // not go through this payload validation).
        $payload = $request->validate([
            'Product_Discount_Name' => ['required', 'string', 'max:255'],
            'Target_Type' => ['required', Rule::in(['product'])],
            'Products_Id' => ['nullable', 'integer', 'exists:Products_Master_T,id'],
            'Product_Discount_Type' => ['required', Rule::in(['percentage', 'fixed'])],
            'Product_Discount_Value' => ['required', 'numeric', 'gt:0'],
            'Start_Date' => ['nullable', 'date'],
            'End_Date' => ['nullable', 'date', 'after_or_equal:Start_Date'],
            'Product_Discount_Is_Active' => ['nullable', 'boolean'],
        ]);

        if (empty($payload['Products_Id'])) {
            throw ValidationException::withMessages([
                'Products_Id' => 'Please choose the target product for this discount.',
            ]);
        }

        if ($payload['Product_Discount_Type'] === 'percentage' && (float) $payload['Product_Discount_Value'] > 100) {
            throw ValidationException::withMessages([
                'Product_Discount_Value' => 'Percentage discounts cannot be more than 100%.',
            ]);
        }

        $payload['Product_Department_Id'] = null;
        $payload['Product_Sub_Department_Id'] = null;
        $payload['Product_Sub_Sub_Department_Id'] = null;

        $payload['Product_Discount_Is_Active'] = (bool) ($payload['Product_Discount_Is_Active'] ?? true);
        $payload['Start_Date'] = $this->normalizeLocalDateInput($payload['Start_Date'] ?? null);
        $payload['End_Date'] = $this->normalizeLocalDateInput($payload['End_Date'] ?? null);

        return $payload;
    }

    private function formatDiscount(ProductDiscount $discount): array
    {
        $data = $discount->toArray();
        $data['Start_Date'] = $this->serializeLocalDate($discount->Start_Date);
        $data['End_Date'] = $this->serializeLocalDate($discount->End_Date);
        $data['effective_status'] = $this->effectiveStatus($discount);
        $data['effective_status_label'] = $this->effectiveStatusLabel($data['effective_status']);
        $data['target_label'] = $this->targetLabel($discount);

        return $data;
    }

    private function normalizeLocalDateInput(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = str_replace('T', ' ', trim($value));

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
            return "{$normalized}:00";
        }

        return Carbon::parse($normalized)->format('Y-m-d H:i:s');
    }

    private function serializeLocalDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }

        return Carbon::parse((string) $value)->format('Y-m-d\TH:i:s');
    }

    private function effectiveStatus(ProductDiscount $discount): string
    {
        if (! (bool) $discount->Product_Discount_Is_Active) {
            return 'unpublished';
        }

        $now = now(config('app.business_timezone', 'Asia/Muscat'))->format('Y-m-d H:i:s');
        $start = $this->serializeLocalDate($discount->Start_Date);
        $end = $this->serializeLocalDate($discount->End_Date);

        if ($start && str_replace('T', ' ', $start) > $now) {
            return 'scheduled';
        }

        if ($end && str_replace('T', ' ', $end) < $now) {
            return 'expired';
        }

        return 'active';
    }

    private function effectiveStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'scheduled' => 'Scheduled',
            'expired' => 'Expired',
            'unpublished' => 'Unpublished',
            default => 'Unknown',
        };
    }

    private function targetLabel(ProductDiscount $discount): string
    {
        return match ($discount->Target_Type) {
            'product' => $discount->product?->Product_Name ?? 'Product target',
            'department' => $discount->department?->Product_Department_Name ?? 'Category target',
            'sub_department' => $discount->subDepartment?->Sub_Department_Name ?? 'Subcategory target',
            'sub_sub_department' => $discount->subSubDepartment?->Product_Sub_Sub_Department_Name ?? 'Sub-subcategory target',
            default => 'Target',
        };
    }

    private function relations(): array
    {
        return ['product', 'department', 'subDepartment', 'subSubDepartment'];
    }
}
