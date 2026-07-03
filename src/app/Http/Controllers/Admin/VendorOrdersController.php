<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrdersPlacedVendors;
use App\Models\ConxDatabaseNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Support\Vendors\VendorPayoutRules;
use InvalidArgumentException;

class VendorOrdersController extends Controller
{
    // 1) List vendor-orders that are still pending (for commission setup)
    public function index(Request $request)
    {
        $status = (string) $request->query('status', 'pending');

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 50));

        $payoutStatus = $request->query('payout_status'); // unpaid / requested / paid
        $search = trim((string) $request->query('q', ''));

        $q = OrdersPlacedVendors::query()
            ->with(['vendor'])
            ->where('Status', $status)
            ->orderByDesc('id');

        // Search (optional)
        if ($search !== '') {
            $q->where(function ($qq) use ($search) {
                $qq->where('Vendor_Order_Code', 'like', "%{$search}%")
                ->orWhere('Orders_Placed_Id', 'like', "%{$search}%")
                ->orWhere('Vendor_Id', 'like', "%{$search}%");
            });
        }

        // Payout status filter (optional)
        if ($payoutStatus) {
            if ($payoutStatus === 'unpaid') {
                $q->where(function ($qq) {
                    $qq->whereNull('Payout_Status')
                    ->orWhere('Payout_Status', 'unpaid');
                });
            } else {
                $q->where('Payout_Status', $payoutStatus);
            }
        }

        // Optional: "needs_commission" filter (ONLY for pending commission screen)
        // Default true for pending, false for commission_set
        $needsCommission = $request->has('needs_commission')
            ? filter_var($request->query('needs_commission'), FILTER_VALIDATE_BOOLEAN)
            : ($status === 'pending');

        if ($needsCommission) {
            $q->where(function ($qq) {
                $qq->whereNull('Commission_Type')
                ->orWhereNull('Commission_Value')
                ->orWhere('Commission_Value', 0);
            });
        }

        $p = $q->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $p->items(),
            'meta' => [
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
            ],
        ]);
    }

    public function getCommissionsSet(Request $request)
{
    $perPage = (int) $request->query('per_page', 15);
    $perPage = max(1, min($perPage, 50));

    $payoutStatus = $request->query('payout_status', 'unpaid');
    $search = trim((string) $request->query('q', ''));

    $q = OrdersPlacedVendors::query()
        ->with(['vendor'])
        ->where('Status', 'commission_set')
        ->orderByDesc('id');

    if ($search !== '') {
        $q->where(function ($qq) use ($search) {
            $qq->where('Vendor_Order_Code', 'like', "%{$search}%")
               ->orWhere('Orders_Placed_Id', 'like', "%{$search}%")
               ->orWhere('Vendor_Id', 'like', "%{$search}%");
        });
    }

    if ($payoutStatus === 'unpaid') {
        $q->where(function ($qq) {
            $qq->whereNull('Payout_Status')
               ->orWhere('Payout_Status', 'unpaid');
        });
    } else {
        $q->where('Payout_Status', $payoutStatus);
    }

    $p = $q->paginate($perPage);

    return response()->json([
        'success' => true,
        'data' => $p->items(),
        'meta' => [
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'per_page' => $p->perPage(),
            'total' => $p->total(),
        ],
    ]);
}

    // 2) Vendor-order details page (header + items)
    public function show(int $id)
    {
        $vendorOrder = OrdersPlacedVendors::query()
            ->where('id', $id)
            ->firstOrFail();

        // Pull items that belong to this vendor-order
        $select = [
            'd.id',
            'd.Products_Id',
            'd.Quantity',
            'd.Price',
            'd.Subtotal',
            'd.Vat',
            'd.Status',
            'p.Product_Name',
            'p.Product_Name_Ar',
            'p.Product_Sku',
            'p.Vendor_Id as Product_Vendor_Id',
        ];

        // Per-line commission snapshot (only when the columns are migrated)
        if (Schema::hasColumn('Orders_Placed_Details_T', 'Commission_Amount')) {
            $select[] = 'd.Commission_Type';
            $select[] = 'd.Commission_Value';
            $select[] = 'd.Commission_Amount';
        }

        $items = DB::table('Orders_Placed_Details_T as d')
            ->leftJoin('Products_Master_T as p', 'p.id', '=', 'd.Products_Id')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'p.Vendor_Id')
            ->select($select)
            ->where('d.Orders_Placed_Vendor_Id', $vendorOrder->id)
            ->orderBy('d.id')
            ->get();

        // Main order header
        $order = DB::table('Orders_Placed_T')
            ->where('id', $vendorOrder->Orders_Placed_Id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'vendor_order' => $vendorOrder,
                'order' => $order,
                'items' => $items,
            ],
        ]);
    }

    // 3) Set commission (percent / fixed)
    public function setCommission(Request $request, int $id)
    {
        $validated = $request->validate([
            'commission_type' => ['required', Rule::in(['percent', 'fixed'])],
            'commission_value' => ['required', 'numeric', 'min:0'],
        ]);

        $vendorOrder = OrdersPlacedVendors::query()->findOrFail($id);

        // Never touch commission after the payout has been made
        if (($vendorOrder->Payout_Status ?? '') === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'This vendor order is already paid out; commission can no longer be changed.'
            ], 422);
        }

        // Calculate commission amount based on vendor order total
        // (Choose base: Total OR Sub_Total. Usually commission is on Sub_Total)
        $base = (float) ($vendorOrder->Sub_Total ?? 0);

        $value = (float) $validated['commission_value'];
        $amount = $validated['commission_type'] === 'percent'
            ? round(($base * $value) / 100, 3)
            : round($value, 3);

        // Safety: don’t exceed base
        if ($amount > $base) {
            return response()->json([
                'success' => false,
                'message' => 'Commission cannot exceed vendor subtotal.'
            ], 422);
        }

        $vendorOrder->Commission_Type = $validated['commission_type'];
        $vendorOrder->Commission_Value = $value;
        $vendorOrder->Commission_Amount = $amount;
        $vendorOrder->Status = 'commission_set';

        // A manual set/override counts as confirmed (works from pending,
        // commission_auto or commission_set).
        if (Schema::hasColumn('Orders_Placed_Vendors_T', 'Commission_Source')) {
            $vendorOrder->Commission_Source = 'manual';
            $vendorOrder->Commission_Confirmed_By = Auth::id();
            $vendorOrder->Commission_Confirmed_At = now()->format('Y-m-d H:i:s'); // ✅ SQL Server-safe
        }

        $vendorOrder->save();

        return response()->json([
            'success' => true,
            'message' => 'Commission updated.',
            'data' => $vendorOrder
        ]);
    }

    /**
     * Reason why an auto-computed commission cannot be confirmed, or null when
     * it is eligible (Status commission_auto + amount present + not paid out).
     */
    private function commissionConfirmIneligibleReason(OrdersPlacedVendors $vendorOrder): ?string
    {
        if (($vendorOrder->Payout_Status ?? '') === 'paid') {
            return 'This vendor order is already paid out.';
        }

        if (($vendorOrder->Status ?? '') !== 'commission_auto') {
            return 'Only auto-computed commissions awaiting confirmation can be confirmed.';
        }

        if (is_null($vendorOrder->Commission_Amount)) {
            return 'Commission amount is missing on this vendor order.';
        }

        return null;
    }

    // Confirm a single auto-computed commission (accountant action)
    public function confirmCommission(Request $request, int $id)
    {
        $vendorOrder = OrdersPlacedVendors::query()->findOrFail($id);

        if ($reason = $this->commissionConfirmIneligibleReason($vendorOrder)) {
            return response()->json([
                'success' => false,
                'message' => $reason,
            ], 422);
        }

        $vendorOrder->Status = 'commission_set';
        $vendorOrder->Commission_Confirmed_By = Auth::id();
        $vendorOrder->Commission_Confirmed_At = now()->format('Y-m-d H:i:s'); // ✅ SQL Server-safe
        $vendorOrder->save();

        return response()->json([
            'success' => true,
            'message' => 'Commission confirmed.',
            'data' => $vendorOrder,
        ]);
    }

    // Confirm many auto-computed commissions at once (skips ineligible ids)
    public function bulkConfirmCommission(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $confirmed = 0;
        $skipped = [];

        foreach ($data['ids'] as $id) {
            $vendorOrder = OrdersPlacedVendors::query()->find($id);

            if (! $vendorOrder) {
                $skipped[] = ['id' => (int) $id, 'reason' => 'Vendor order not found.'];
                continue;
            }

            if ($reason = $this->commissionConfirmIneligibleReason($vendorOrder)) {
                $skipped[] = ['id' => (int) $id, 'reason' => $reason];
                continue;
            }

            $vendorOrder->Status = 'commission_set';
            $vendorOrder->Commission_Confirmed_By = Auth::id();
            $vendorOrder->Commission_Confirmed_At = now()->format('Y-m-d H:i:s'); // ✅ SQL Server-safe
            $vendorOrder->save();

            $confirmed++;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'confirmed_count' => $confirmed,
                'skipped' => $skipped,
            ],
        ]);
    }


    public function markPayoutPaid(Request $request, int $id)
{
    $validated = $request->validate([
        'payout_at' => ['nullable', 'string'], // we'll parse manually
        'reference' => ['nullable', 'string', 'max:100'],
    ]);

    $vendorOrder = OrdersPlacedVendors::query()->findOrFail($id);

    // Must have commission set first
    if (is_null($vendorOrder->Commission_Amount) || (float)$vendorOrder->Commission_Amount < 0) {
        return response()->json([
            'success' => false,
            'message' => 'Commission must be set before payout.'
        ], 422);
    }

    // Auto-computed commissions must be confirmed (Status commission_set) first
    if (($vendorOrder->Status ?? '') !== 'commission_set') {
        return response()->json([
            'success' => false,
            'message' => 'Commission must be confirmed before payout.'
        ], 422);
    }

    // Prevent double payout
    if (($vendorOrder->Payout_Status ?? '') === 'paid') {
        return response()->json([
            'success' => false,
            'message' => 'This vendor order is already marked as paid.'
        ], 422);
    }

    try {
        $payoutAmount = VendorPayoutRules::payoutAmount($vendorOrder);
    } catch (InvalidArgumentException $exception) {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage()
        ], 422);
    }

    // ✅ Normalize payout_at for SQL Server
    $payoutAt = now();

    if (!empty($validated['payout_at'])) {
        $raw = trim((string) $validated['payout_at']);

        try {
            // Handles <input type="datetime-local"> => "2026-02-23T01:38"
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw)) {
                $payoutAt = Carbon::createFromFormat('Y-m-d\TH:i', $raw);
            } else {
                $payoutAt = Carbon::parse($raw);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payout date/time format.'
            ], 422);
        }
    }

    $vendorOrder->Payout_Amount = $payoutAmount;
    $vendorOrder->Payout_Status = 'paid';
    $vendorOrder->Payout_At = $payoutAt->format('Y-m-d H:i:s'); // ✅ SQL Server-safe
    $vendorOrder->Payout_Reference = $validated['reference'] ?? null;
    $vendorOrder->save();

    // Notify the vendor that their payout has been processed (best-effort).
    try {
        ConxDatabaseNotification::create([
            'type'            => 'App\\Notifications\\VendorPayoutPaid',
            'notifiable_type' => 'App\\Models\\Vendor',
            'notifiable_id'   => $vendorOrder->Vendor_Id,
            'data'            => [
                'title'   => 'Payout processed',
                'message' => 'Your payout of ' . number_format((float) $payoutAmount, 3)
                    . ' for order ' . $vendorOrder->Vendor_Order_Code . ' has been processed.',
                'url'     => '/payouts',
            ],
        ]);
    } catch (\Throwable $e) {
        report($e);
    }

    return response()->json([
        'success' => true,
        'message' => 'Payout marked as paid.',
        'data' => [
            'id' => $vendorOrder->id,
            'vendor_id' => $vendorOrder->Vendor_Id,
            'payout_amount' => $vendorOrder->Payout_Amount,
            'payout_status' => $vendorOrder->Payout_Status,
            'payout_at' => $vendorOrder->Payout_At,
            'payout_reference' => $vendorOrder->Payout_Reference,
        ],
    ]);
}

}
