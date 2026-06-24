<?php

namespace App\Services\Orders;

use App\Models\OrderProcessLog;
use App\Models\OrdersPlaced;
use App\Models\OrdersPlacedDetails;
use App\Models\OrdersPlacedDetailsAdjustment;
use App\Models\OrdersPlacedVendors;
use App\Models\ProductMaster;
use App\Models\ProductStockMovement;
use App\Support\Orders\OrderReturnRefundCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderReturnRefundService
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array{url?: string|null, mime?: string|null} $signature
     *
     * @return array<string, mixed>
     */
    public function apply(
        OrdersPlaced $order,
        array $items,
        array $signature,
        ?object $actor,
        ?string $note,
    ): array {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['At least one order product line is required.'],
            ]);
        }

        return DB::transaction(function () use ($order, $items, $signature, $actor, $note) {
            $adjustments = [];
            $affectedVendorOrderIds = [];

            foreach ($items as $index => $item) {
                $lineId = (int) ($item['order_detail_id'] ?? $item['id'] ?? 0);

                if ($lineId <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.order_detail_id" => ['The order product line is required.'],
                    ]);
                }

                $detail = OrdersPlacedDetails::query()
                    ->where('Orders_Placed_Id', $order->id)
                    ->where('id', $lineId)
                    ->lockForUpdate()
                    ->first();

                if (!$detail) {
                    throw ValidationException::withMessages([
                        "items.{$index}.order_detail_id" => ['The selected order product line was not found for this order.'],
                    ]);
                }

                if ((string) $detail->Status === 'cancelled') {
                    throw ValidationException::withMessages([
                        "items.{$index}.order_detail_id" => ['Cancelled product lines cannot be returned or refunded.'],
                    ]);
                }

                $reason = trim((string) ($item['reason'] ?? $note ?? ''));

                if ($reason === '') {
                    throw ValidationException::withMessages([
                        "items.{$index}.reason" => ['A return/refund reason is required.'],
                    ]);
                }

                $returnQuantity = (int) ($item['quantity'] ?? $item['return_quantity'] ?? 0);
                $refundAmount = $item['refund_amount'] ?? 0;
                $restock = filter_var($item['restock'] ?? false, FILTER_VALIDATE_BOOLEAN);

                try {
                    $plan = OrderReturnRefundCalculator::linePlan(
                        line: $detail->getAttributes(),
                        returnQuantity: $returnQuantity,
                        refundAmount: $refundAmount,
                        restock: $restock,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => [$exception->getMessage()],
                    ]);
                }

                $now = now();
                $detail->forceFill([
                    'Sold_Amount' => $plan['sold_amount'],
                    'Returned_Quantity' => $plan['returned_quantity'],
                    'Refunded_Amount' => $plan['refunded_amount'],
                    'Net_Amount' => $plan['net_amount'],
                    'Return_State' => $plan['return_state'],
                    'Refund_State' => $plan['refund_state'],
                    'Last_Returned_At' => $returnQuantity > 0 ? $now : $detail->Last_Returned_At,
                    'Last_Refunded_At' => ((float) $plan['refund_amount']) > 0 ? $now : $detail->Last_Refunded_At,
                    'Status' => $plan['next_status'],
                ])->save();

                $adjustment = OrdersPlacedDetailsAdjustment::create([
                    'Orders_Placed_Id' => $order->id,
                    'Orders_Placed_Details_Id' => $detail->id,
                    'Orders_Placed_Vendor_Id' => $detail->Orders_Placed_Vendor_Id,
                    'Products_Id' => $detail->Products_Id,
                    'Vendor_Id' => $detail->Vendor_Id,
                    'Adjustment_Type' => $plan['adjustment_type'],
                    'Quantity' => $plan['return_quantity'],
                    'Amount' => $plan['refund_amount'],
                    'Restock_Quantity' => $plan['restock_quantity'],
                    'Reason' => $reason,
                    'Actor_User_Id' => $actor?->id,
                    'Actor_Name' => $this->actorName($actor),
                    'Signature_Url' => $signature['url'] ?? null,
                    'Signature_Mime' => $signature['mime'] ?? null,
                    'Metadata' => [
                        'previous_returned_quantity' => $plan['previous_returned_quantity'],
                        'previous_refunded_amount' => $plan['previous_refunded_amount'],
                        'return_state' => $plan['return_state'],
                        'refund_state' => $plan['refund_state'],
                        'resolution_state' => $plan['resolution_state'],
                        'net_quantity' => $plan['net_quantity'],
                        'net_amount' => $plan['net_amount'],
                    ],
                ]);

                OrderProcessLog::create([
                    'Orders_Placed_Id' => $order->id,
                    'Orders_Placed_Details_Id' => $detail->id,
                    'Step_Code' => 'LINE_' . strtoupper($plan['adjustment_type']),
                    'Status' => $plan['adjustment_type'],
                    'Is_External' => false,
                    'Actor_User_Id' => $actor?->id,
                    'Actor_Name' => $this->actorName($actor) ?? 'System',
                    'Actor_Role' => $actor?->role ?? null,
                    'Signed_At' => $now,
                    'Signature_Url' => $signature['url'] ?? null,
                    'Signature_Mime' => $signature['mime'] ?? null,
                    'Notes' => trim(sprintf(
                        'Qty: %d. Refund: %s. Restock: %d. Reason: %s',
                        $plan['return_quantity'],
                        $plan['refund_amount'],
                        $plan['restock_quantity'],
                        $reason,
                    )),
                ]);

                if ($plan['restock_quantity'] > 0) {
                    $this->restockProduct($detail, (int) $plan['restock_quantity'], $actor, $reason);
                }

                if ($detail->Orders_Placed_Vendor_Id) {
                    $affectedVendorOrderIds[] = (int) $detail->Orders_Placed_Vendor_Id;
                }

                $adjustments[] = [
                    'id' => $adjustment->id,
                    'detail_id' => $detail->id,
                    'type' => $plan['adjustment_type'],
                    'quantity' => $plan['return_quantity'],
                    'refund_amount' => $plan['refund_amount'],
                    'restock_quantity' => $plan['restock_quantity'],
                    'return_state' => $plan['return_state'],
                    'refund_state' => $plan['refund_state'],
                    'net_amount' => $plan['net_amount'],
                ];
            }

            $vendorAdjustments = $this->refreshVendorOrders(array_unique($affectedVendorOrderIds));
            $orderStatus = $this->refreshParentOrderStatus($order);

            return [
                'order_id' => $order->id,
                'order_status' => $orderStatus,
                'adjustment_count' => count($adjustments),
                'adjustments' => $adjustments,
                'vendor_adjustments' => $vendorAdjustments,
            ];
        });
    }

    private function restockProduct(OrdersPlacedDetails $detail, int $quantity, ?object $actor, string $reason): void
    {
        $product = ProductMaster::query()
            ->where('id', $detail->Products_Id)
            ->lockForUpdate()
            ->first();

        if (!$product) {
            return;
        }

        $previousStock = (int) ($product->Product_Stock ?? 0);
        $newStock = $previousStock + $quantity;
        $currentStatus = (string) ($product->Status ?? 'available');

        $product->forceFill([
            'Product_Stock' => $newStock,
            'Status' => $currentStatus === 'discontinued'
                ? 'discontinued'
                : ($newStock > 0 ? 'available' : 'out_of_stock'),
        ])->save();

        ProductStockMovement::create([
            'Products_Id' => $product->id,
            'Vendor_Id' => $detail->Vendor_Id,
            'Movement_Type' => 'return_restock',
            'Quantity_Delta' => $quantity,
            'Quantity' => $quantity,
            'Previous_Stock' => $previousStock,
            'New_Stock' => $newStock,
            'Actor_Type' => 'admin',
            'Actor_Id' => $actor?->id,
            'Actor_Name' => $this->actorName($actor),
            'Notes' => "Return restock from order line {$detail->id}. {$reason}",
        ]);
    }

    /**
     * @param list<int> $vendorOrderIds
     *
     * @return list<array<string, mixed>>
     */
    private function refreshVendorOrders(array $vendorOrderIds): array
    {
        $results = [];

        foreach ($vendorOrderIds as $vendorOrderId) {
            $vendorOrder = OrdersPlacedVendors::query()
                ->where('id', $vendorOrderId)
                ->lockForUpdate()
                ->first();

            if (!$vendorOrder) {
                continue;
            }

            $totals = OrdersPlacedDetails::query()
                ->where('Orders_Placed_Vendor_Id', $vendorOrder->id)
                ->selectRaw('SUM(COALESCE(Returned_Quantity, 0)) as returned_quantity')
                ->selectRaw('SUM(COALESCE(Refunded_Amount, 0)) as refunded_amount')
                ->first();

            try {
                $plan = OrderReturnRefundCalculator::vendorPlan(
                    vendorOrder: $vendorOrder->getAttributes(),
                    refundedAmount: (string) ($totals?->refunded_amount ?? 0),
                    returnedQuantity: (int) ($totals?->returned_quantity ?? 0),
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'vendor_order' => [$exception->getMessage()],
                ]);
            }

            $vendorOrder->forceFill([
                'Returned_Quantity' => $plan['returned_quantity'],
                'Refunded_Amount' => $plan['refunded_amount'],
                'Net_Sub_Total' => $plan['net_sub_total'],
                'Adjusted_Commission_Amount' => $plan['adjusted_commission_amount'],
                'Net_Payout_Amount' => $plan['net_payout_amount'],
                'Payout_Adjustment_Amount' => $plan['payout_adjustment_amount'],
            ])->save();

            $results[] = [
                'vendor_order_id' => $vendorOrder->id,
                'returned_quantity' => $plan['returned_quantity'],
                'refunded_amount' => $plan['refunded_amount'],
                'net_sub_total' => $plan['net_sub_total'],
                'adjusted_commission_amount' => $plan['adjusted_commission_amount'],
                'net_payout_amount' => $plan['net_payout_amount'],
                'payout_adjustment_amount' => $plan['payout_adjustment_amount'],
            ];
        }

        return $results;
    }

    private function refreshParentOrderStatus(OrdersPlaced $order): string
    {
        $details = OrdersPlacedDetails::query()
            ->where('Orders_Placed_Id', $order->id)
            ->get(['Status', 'Return_State']);

        $active = $details->filter(fn ($detail) => (string) $detail->Status !== 'cancelled');

        if ($active->isEmpty()) {
            $order->forceFill(['Status' => 'cancelled'])->save();

            return 'cancelled';
        }

        $allReturned = $active->every(function ($detail) {
            return (string) $detail->Return_State === 'returned'
                || (string) $detail->Status === 'returned';
        });

        if ($allReturned) {
            $order->forceFill(['Status' => 'returned'])->save();

            return 'returned';
        }

        return (string) $order->fresh()->Status;
    }

    private function actorName(?object $actor): ?string
    {
        return $actor?->User_Name
            ?? $actor?->name
            ?? $actor?->email
            ?? null;
    }
}
