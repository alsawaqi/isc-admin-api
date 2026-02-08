<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrdersPlacedVendors;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class VendorOrdersController extends Controller
{
    // 1) List vendor-orders that are still pending (for commission setup)
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending'); // default

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 50));

        $q = OrdersPlacedVendors::query()
            ->with([
                'vendor',
            ])
            ->where('Status', $status)
            ->orderByDesc('id');

        // Optional: filter "commission not set yet"
        if ($request->boolean('needs_commission', true)) {
            $q->whereNull('Commission_Type')
              ->orWhere('Commission_Value', 0)
              ->orWhereNull('Commission_Value');
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

    public function getcommissionsset(Request $request)
    {
        $commissions = Commission::query()
            ->where('Status', 'commission_set')
            ->get();

        return response()->json($commissions);
    }

    // 2) Vendor-order details page (header + items)
    public function show(int $id)
    {
        $vendorOrder = OrdersPlacedVendors::query()
            ->where('id', $id)
            ->firstOrFail();

        // Pull items that belong to this vendor-order
        $items = DB::table('Orders_Placed_Details_T as d')
            ->leftJoin('Products_Master_T as p', 'p.id', '=', 'd.Products_Id')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'p.Vendor_Id')
            ->select([
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
            ])
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
        $vendorOrder->save();

        return response()->json([
            'success' => true,
            'message' => 'Commission updated.',
            'data' => $vendorOrder
        ]);
    }


    public function markPayoutPaid(Request $request, int $id)
    {
        $validated = $request->validate([
            // optional: allow passing payout time (otherwise use now)
            'payout_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $vendorOrder = OrdersPlacedVendors::query()->findOrFail($id);

        // Must have commission set first
        if (!$vendorOrder->Commission_Amount || (float)$vendorOrder->Commission_Amount < 0) {
            return response()->json([
                'success' => false,
                'message' => 'Commission must be set before payout.'
            ], 422);
        }

        // Prevent double payout
        if (($vendorOrder->Payout_Status ?? '') === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'This vendor order is already marked as paid.'
            ], 422);
        }

        $subTotal = (float) ($vendorOrder->Sub_Total ?? 0);
        $commission = (float) ($vendorOrder->Commission_Amount ?? 0);

        $payoutAmount = round($subTotal - $commission, 3);

        if ($payoutAmount < 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payout amount (negative). Check subtotal/commission.'
            ], 422);
        }

        $vendorOrder->Payout_Amount = $payoutAmount;
        $vendorOrder->Payout_Status = 'paid';
        $vendorOrder->Payout_At = $validated['payout_at'] ?? now();
        $vendorOrder->Payout_Reference = $validated['reference'] ?? null;
        $vendorOrder->save();

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
