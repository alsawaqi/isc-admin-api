<?php

namespace App\Http\Controllers;

use App\Models\ProductMaster;
use App\Models\ProductStockMovement;
use App\Services\Notifications\CustomerNotificationService;
use App\Support\Notifications\BackInStockAlertPlanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductStockController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $sortBy = (string) $request->query('sort_by', 'Product_Stock');
        $sortDir = strtolower((string) $request->query('sort_dir', 'asc'));

        $departmentId = $request->query('product_department_id', $request->query('department_id'));
        $subDepartmentId = $request->query('product_sub_department_id', $request->query('sub_department_id'));
        $subSubDepartmentId = $request->query('product_sub_sub_department_id', $request->query('sub_sub_department_id'));

        if (!in_array($sortBy, ['id', 'Product_Name', 'Product_Code', 'Product_Sku', 'Product_Stock', 'Status', 'created_at'], true)) {
            $sortBy = 'Product_Stock';
        }

        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'asc';
        }

        $products = ProductMaster::query()
            ->whereNull('Vendor_Id')
            ->with([
                'department:id,Product_Department_Name,Product_Department_Name_Ar',
                'subDepartment:id,Sub_Department_Name,Sub_Department_Name_Ar',
                'subSubDepartment:id,Product_Sub_Sub_Department_Name,Product_Sub_Sub_Department_Name_Ar',
            ])
            ->when($departmentId !== null && $departmentId !== '', fn ($query) => $query->where('Product_Department_Id', $departmentId))
            ->when($subDepartmentId !== null && $subDepartmentId !== '', fn ($query) => $query->where('Product_Sub_Department_Id', $subDepartmentId))
            ->when($subSubDepartmentId !== null && $subSubDepartmentId !== '', fn ($query) => $query->where('Product_Sub_Sub_Department_Id', $subSubDepartmentId))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($where) use ($search) {
                    $where->where('Product_Name', 'like', "%{$search}%")
                        ->orWhere('Product_Code', 'like', "%{$search}%")
                        ->orWhere('Product_Sku', 'like', "%{$search}%")
                        ->orWhere('Inhouse_Barcode_Source', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);

        return response()->json($products);
    }

    public function adjust(Request $request, int $id)
    {
        $validated = $request->validate([
            'movement_type' => ['required', 'in:increase,decrease,set'],
            'quantity' => ['required_unless:movement_type,set', 'nullable', 'integer', 'min:1'],
            'new_stock' => ['required_if:movement_type,set', 'nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($validated, $id) {
            $product = ProductMaster::query()
                ->where('id', $id)
                ->whereNull('Vendor_Id')
                ->lockForUpdate()
                ->firstOrFail();

            $previousStock = (int) ($product->Product_Stock ?? 0);
            $movementType = $validated['movement_type'];
            $quantity = $movementType === 'set'
                ? abs((int) $validated['new_stock'] - $previousStock)
                : (int) $validated['quantity'];

            if ($movementType === 'increase') {
                $newStock = $previousStock + $quantity;
                $delta = $quantity;
            } elseif ($movementType === 'decrease') {
                $newStock = $previousStock - $quantity;
                $delta = -$quantity;
            } else {
                $newStock = (int) $validated['new_stock'];
                $delta = $newStock - $previousStock;
            }

            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Stock cannot go below zero.'],
                ]);
            }

            $currentStatus = (string) ($product->Status ?? 'available');

            $product->forceFill([
                'Product_Stock' => $newStock,
                'Status' => $currentStatus === 'discontinued'
                    ? 'discontinued'
                    : ($newStock > 0 ? 'available' : 'out_of_stock'),
            ])->save();

            $user = Auth::user();
            $movement = ProductStockMovement::create([
                'Products_Id' => $product->id,
                'Vendor_Id' => null,
                'Movement_Type' => $movementType,
                'Quantity_Delta' => $delta,
                'Quantity' => $quantity,
                'Previous_Stock' => $previousStock,
                'New_Stock' => $newStock,
                'Actor_Type' => 'admin',
                'Actor_Id' => $user?->id,
                'Actor_Name' => $user?->name ?? $user?->User_Name ?? $user?->email,
                'Notes' => $validated['notes'] ?? null,
            ]);

            return [
                'product' => $product->fresh(['department', 'subDepartment', 'subSubDepartment']),
                'movement' => $movement,
            ];
        });

        $product = $result['product'];
        $movement = $result['movement'];

        if (BackInStockAlertPlanner::shouldNotify(
            previousStock: (int) $movement->Previous_Stock,
            newStock: (int) $movement->New_Stock,
            status: (string) $product->Status,
        )) {
            try {
                app(CustomerNotificationService::class)->notifyBackInStock($product);
            } catch (\Throwable $exception) {
                Log::error('Failed to send back-in-stock notifications', [
                    'product_id' => $product->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock updated successfully.',
            'data' => $result,
        ]);
    }

    public function movements(Request $request, int $id)
    {
        ProductMaster::query()
            ->where('id', $id)
            ->whereNull('Vendor_Id')
            ->firstOrFail();

        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        return response()->json(
            ProductStockMovement::query()
                ->where('Products_Id', $id)
                ->orderByDesc('created_at')
                ->paginate($perPage)
        );
    }
}
