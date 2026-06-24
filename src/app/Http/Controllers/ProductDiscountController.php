<?php

namespace App\Http\Controllers;

use App\Helpers\CodeGenerator;
use App\Models\ProductDiscount;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
        $payload = $request->validate([
            'Product_Discount_Name' => ['required', 'string', 'max:255'],
            'Target_Type' => ['required', Rule::in(['product', 'department', 'sub_department', 'sub_sub_department'])],
            'Products_Id' => ['nullable', 'integer', 'exists:Products_Master_T,id'],
            'Product_Department_Id' => ['nullable', 'integer', 'exists:Products_Departments_T,id'],
            'Product_Sub_Department_Id' => ['nullable', 'integer', 'exists:Products_Sub_Department_T,id'],
            'Product_Sub_Sub_Department_Id' => ['nullable', 'integer', 'exists:Products_Sub_Sub_Department_T,id'],
            'Product_Discount_Type' => ['required', Rule::in(['percentage', 'fixed'])],
            'Product_Discount_Value' => ['required', 'numeric', 'gt:0'],
            'Start_Date' => ['nullable', 'date'],
            'End_Date' => ['nullable', 'date', 'after_or_equal:Start_Date'],
            'Product_Discount_Is_Active' => ['nullable', 'boolean'],
        ]);

        $type = $payload['Target_Type'];
        $requiredByType = [
            'product' => 'Products_Id',
            'department' => 'Product_Department_Id',
            'sub_department' => 'Product_Sub_Department_Id',
            'sub_sub_department' => 'Product_Sub_Sub_Department_Id',
        ];

        $requiredColumn = $requiredByType[$type];
        if (empty($payload[$requiredColumn])) {
            throw ValidationException::withMessages([
                $requiredColumn => 'Please choose the target for this discount.',
            ]);
        }

        if ($payload['Product_Discount_Type'] === 'percentage' && (float) $payload['Product_Discount_Value'] > 100) {
            throw ValidationException::withMessages([
                'Product_Discount_Value' => 'Percentage discounts cannot be more than 100%.',
            ]);
        }

        if ($type === 'product') {
            $payload['Product_Department_Id'] = null;
            $payload['Product_Sub_Department_Id'] = null;
            $payload['Product_Sub_Sub_Department_Id'] = null;
        } elseif ($type === 'department') {
            $payload['Products_Id'] = null;
            $payload['Product_Sub_Department_Id'] = null;
            $payload['Product_Sub_Sub_Department_Id'] = null;
        } elseif ($type === 'sub_department') {
            $payload['Products_Id'] = null;
            $payload['Product_Sub_Sub_Department_Id'] = null;
        } else {
            $payload['Products_Id'] = null;
        }

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
            'department' => $discount->department?->Product_Department_Name ?? 'Department target',
            'sub_department' => $discount->subDepartment?->Sub_Department_Name ?? 'Sub department target',
            'sub_sub_department' => $discount->subSubDepartment?->Product_Sub_Sub_Department_Name ?? 'Sub sub department target',
            default => 'Target',
        };
    }

    private function relations(): array
    {
        return ['product', 'department', 'subDepartment', 'subSubDepartment'];
    }
}
