<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UnpaidAmwalOrderCancellationService
{
    /**
     * Explicitly cancel the complete unpaid order and release local reservations.
     * A later signed capture is deliberately handled by the customer API as
     * paid_requires_review; inventory and loyalty are never re-reserved here.
     *
     * @param array{url?: string|null, mime?: string|null} $signature
     * @return array{idempotent: bool, released_lines: int, released_loyalty_points: int, cart_restoration: array<string, mixed>}
     */
    public function cancel(
        int $orderId,
        int $actorId,
        string $actorName,
        ?string $actorRole,
        array $signature,
        ?string $note,
    ): array {
        return DB::transaction(function () use (
            $orderId,
            $actorId,
            $actorName,
            $actorRole,
            $signature,
            $note,
        ) {
            // Match storefront checkout/cancellation lock order. The initial
            // identity read is not authoritative; it only tells us which
            // customer row to serialize before locking and re-reading the order.
            $orderIdentity = DB::table('Orders_Placed_T')
                ->where('id', $orderId)
                ->first(['Customers_Id']);

            if (!$orderIdentity) {
                throw new NotFoundHttpException('Order not found.');
            }

            $lockedCustomer = DB::table('Customers_Master_T')
                ->where('id', $orderIdentity->Customers_Id)
                ->lockForUpdate()
                ->first(['id']);

            if (!$lockedCustomer) {
                throw new ConflictHttpException('The order customer could not be locked for cart restoration.');
            }

            $order = DB::table('Orders_Placed_T')
                ->where('id', $orderId)
                ->where('Customers_Id', $lockedCustomer->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new NotFoundHttpException('Order not found.');
            }

            $attempts = Schema::hasTable('Payment_Gateway_Attempts_T')
                ? DB::table('Payment_Gateway_Attempts_T')
                    ->where('Orders_Placed_Id', $orderId)
                    ->where('Gateway', 'amwal_smartbox')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();

            $saleHeaders = DB::table('Sales_Transaction_Header_T')
                ->where('Orders_Placed_Id', $orderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $payments = $saleHeaders->isNotEmpty()
                ? DB::table('Sales_Transactions_Details_T')
                    ->whereIn('Sales_Transaction_Header_Id', $saleHeaders->pluck('id')->all())
                    ->where('Payment_Gateway', 'amwal_smartbox')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();

            if ($attempts->isEmpty() && $payments->isEmpty()) {
                throw new ConflictHttpException('The AmwalPay payment record could not be verified.');
            }

            $capturedStates = ['paid', 'paid_requires_review'];
            $captured = in_array(strtolower((string) ($order->Payment_Status ?? '')), $capturedStates, true)
                || $attempts->contains(
                    fn ($attempt) => in_array(strtolower((string) ($attempt->Status ?? '')), $capturedStates, true)
                )
                || $payments->contains(
                    fn ($payment) => in_array(strtolower((string) ($payment->Payment_Status ?? '')), $capturedStates, true)
                );

            if ($captured) {
                throw new ConflictHttpException(
                    'Refund or void this AmwalPay transaction in APG before cancelling the order.',
                );
            }

            $activeDetails = DB::table('Orders_Placed_Details_T')
                ->where('Orders_Placed_Id', $orderId)
                ->where('Status', '<>', 'cancelled')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (strtolower((string) ($order->Status ?? '')) === 'cancelled') {
                if ($activeDetails->isNotEmpty()) {
                    throw new ConflictHttpException('The cancelled order has active product lines and requires reconciliation.');
                }

                return [
                    'idempotent' => true,
                    'released_lines' => 0,
                    'released_loyalty_points' => 0,
                    'cart_restoration' => $this->storedCartRestoration($payments, $attempts)
                        ?? $this->legacyCartRestoration(),
                ];
            }

            if (!in_array(strtolower((string) ($order->Status ?? '')), ['pending', 'on-hold'], true)) {
                throw new ConflictHttpException('Only a pending unpaid AmwalPay order can be cancelled.');
            }

            if ($activeDetails->isEmpty()) {
                throw new ConflictHttpException('The unpaid order has no active product lines to release.');
            }

            $productIds = $activeDetails
                ->pluck('Products_Id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->sort()
                ->values();
            $products = DB::table('Products_Master_T')
                ->whereIn('id', $productIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw new ConflictHttpException('One or more reserved products could not be restored.');
            }

            $loyaltyPoints = max(0, (int) ($order->Loyalty_Points_Redeemed ?? 0));
            $loyalty = null;
            $reversalCode = 'LOYREV-'.$orderId;

            if ($loyaltyPoints > 0) {
                $existingReversal = DB::table('Customers_Loyalty_Transactions_T')
                    ->where('Loyalty_Transaction_Code', $reversalCode)
                    ->lockForUpdate()
                    ->first();

                if ($existingReversal) {
                    throw new ConflictHttpException('The loyalty reservation was already released and requires reconciliation.');
                }

                $loyalty = DB::table('Customers_Loyalty_T')
                    ->where('Customer_Id', $order->Customers_Id)
                    ->lockForUpdate()
                    ->first();

                if (!$loyalty || (int) ($loyalty->Points_Redeemed ?? 0) < $loyaltyPoints) {
                    throw new ConflictHttpException('The reserved loyalty balance could not be verified.');
                }
            }

            $now = now();
            $quantitiesByProduct = $activeDetails
                ->groupBy(fn ($detail) => (int) $detail->Products_Id)
                ->map(fn ($details) => (int) $details->sum(fn ($detail) => (int) $detail->Quantity));
            $detailIdsByProduct = $activeDetails
                ->groupBy(fn ($detail) => (int) $detail->Products_Id)
                ->map(fn ($details) => $details->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
            $hasProductIsActive = Schema::hasColumn('Products_Master_T', 'Is_Active');
            $hasCartTable = Schema::hasTable('Customers_Carts_T');
            $hasCartSoftDeletes = $hasCartTable
                && Schema::hasColumn('Customers_Carts_T', 'deleted_at');
            $cartRestoration = $this->emptyCartRestoration();

            foreach ($quantitiesByProduct as $productId => $quantity) {
                $product = $products->get((int) $productId);
                $previousStock = (int) ($product->Product_Stock ?? 0);
                $newStock = $previousStock + $quantity;
                $currentStatus = (string) ($product->Status ?? 'available');
                $isDeleted = !empty($product->deleted_at);
                $isActive = !$hasProductIsActive || (int) ($product->Is_Active ?? 0) === 1;
                $nextStatus = !$isDeleted && $isActive
                    && strtolower($currentStatus) === 'out_of_stock' && $newStock > 0
                    ? 'available'
                    : $currentStatus;

                DB::table('Products_Master_T')->where('id', $productId)->update([
                    'Product_Stock' => $newStock,
                    'Status' => $nextStatus,
                    'updated_at' => $now,
                ]);

                if (Schema::hasTable('Product_Stock_Movements_T')) {
                    $vendorId = $activeDetails
                        ->first(fn ($detail) => (int) $detail->Products_Id === (int) $productId)
                        ?->Vendor_Id;

                    DB::table('Product_Stock_Movements_T')->insert([
                        'Products_Id' => $productId,
                        'Vendor_Id' => $vendorId,
                        'Movement_Type' => 'order_cancellation_release',
                        'Quantity_Delta' => $quantity,
                        'Quantity' => $quantity,
                        'Previous_Stock' => $previousStock,
                        'New_Stock' => $newStock,
                        'Actor_Type' => 'admin',
                        'Actor_Id' => $actorId,
                        'Actor_Name' => $actorName,
                        'Notes' => "Released unpaid AmwalPay order {$orderId} reservation.",
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $orderDetailIds = $detailIdsByProduct->get((int) $productId, []);
                $skipReason = match (true) {
                    !$hasCartTable => 'cart_table_unavailable',
                    $isDeleted => 'product_deleted',
                    !$isActive => 'product_inactive',
                    default => null,
                };

                if ($skipReason !== null) {
                    $cartRestoration['skipped'][] = [
                        'product_id' => (int) $productId,
                        'quantity' => $quantity,
                        'order_detail_ids' => $orderDetailIds,
                        'reason' => $skipReason,
                    ];
                    $cartRestoration['skipped_lines']++;
                    $cartRestoration['skipped_quantity'] += $quantity;
                    $cartRestoration['review_required'] = true;

                    continue;
                }

                $cartQuery = DB::table('Customers_Carts_T')
                    ->where('Customers_Id', $order->Customers_Id)
                    ->where('Products_Id', $productId);

                if ($hasCartSoftDeletes) {
                    $cartQuery->whereNull('deleted_at');
                }

                $cartRow = $cartQuery->lockForUpdate()->first();
                $cartAction = 'created';

                if ($cartRow) {
                    DB::table('Customers_Carts_T')->where('id', $cartRow->id)->update([
                        'Quantity' => (int) $cartRow->Quantity + $quantity,
                        'updated_at' => $now,
                    ]);
                    $cartId = (int) $cartRow->id;
                    $cartAction = 'increased';
                } else {
                    $cartInsert = [
                        'Customers_Id' => (int) $order->Customers_Id,
                        'Products_Id' => (int) $productId,
                        'Quantity' => $quantity,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($hasCartSoftDeletes) {
                        $cartInsert['deleted_at'] = null;
                    }

                    $cartId = (int) DB::table('Customers_Carts_T')->insertGetId($cartInsert);
                }

                $cartRestoration['restored'][] = [
                    'product_id' => (int) $productId,
                    'quantity' => $quantity,
                    'cart_id' => $cartId,
                    'action' => $cartAction,
                    'order_detail_ids' => $orderDetailIds,
                ];
                $cartRestoration['restored_lines']++;
                $cartRestoration['restored_quantity'] += $quantity;
            }

            foreach ($activeDetails as $detail) {
                DB::table('Orders_Placed_Details_T')->where('id', $detail->id)->update([
                    'Status' => 'cancelled',
                    'updated_at' => $now,
                ]);

                $cancelledDetailId = DB::table('Orders_Placed_Details_Cancelled_T')->insertGetId([
                    'Orders_Placed_Details_Id' => $detail->id,
                    'Orders_Placed_Id' => $orderId,
                    'Cancelled_By_Users_Id' => $actorId,
                    'Cancellation_Reason' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('Order_Process_Log_T')->insert([
                    'Orders_Placed_Id' => $orderId,
                    'Orders_Placed_Details_Id' => $detail->id,
                    'Orders_Placed_Details_Cancelled_Id' => $cancelledDetailId,
                    'Step_Code' => 'CANCELLED',
                    'Status' => 'Cancelled',
                    'Is_External' => 0,
                    'Actor_User_Id' => $actorId,
                    'Actor_Name' => $actorName,
                    'Actor_Role' => $actorRole,
                    'Signed_At' => $now,
                    'Signature_Url' => $signature['url'] ?? null,
                    'Signature_Mime' => $signature['mime'] ?? null,
                    'Notes' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (Schema::hasTable('Orders_Placed_Vendors_T')) {
                DB::table('Orders_Placed_Vendors_T')->where('Orders_Placed_Id', $orderId)->update([
                    'Status' => 'cancelled',
                    'updated_at' => $now,
                ]);
            }

            DB::table('Orders_Placed_T')->where('id', $orderId)->update([
                'Status' => 'cancelled',
                'Payment_Status' => 'cancelled',
                'updated_at' => $now,
            ]);

            foreach ($payments as $payment) {
                $metadata = json_decode((string) ($payment->Payment_Metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['cancelled_before_settlement'] = true;
                $metadata['cancelled_at'] = $now->toIso8601String();
                $metadata['cancelled_by'] = $actorId;
                $metadata['cancellation_source'] = 'admin';
                $metadata['cart_restoration'] = $cartRestoration;

                DB::table('Sales_Transactions_Details_T')->where('id', $payment->id)->update([
                    'Payment_Status' => 'cancelled',
                    'Card_Transaction_Id' => null,
                    'Card_Error_Code' => null,
                    'Card_Error_Message' => 'Order cancelled before payment settlement.',
                    'Payment_Metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
            }

            foreach ($attempts as $attempt) {
                $attemptMetadata = json_decode((string) ($attempt->Metadata ?? ''), true);
                $attemptMetadata = is_array($attemptMetadata) ? $attemptMetadata : [];
                $attemptMetadata['cart_restoration'] = $cartRestoration;
                $attemptUpdate = [
                    'Metadata' => json_encode($attemptMetadata, JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ];
                $isPendingAttempt = strtolower((string) ($attempt->Status ?? '')) === 'pending';

                if ($isPendingAttempt) {
                    $attemptUpdate['Status'] = 'cancelled';
                    $attemptUpdate['Completed_At'] = $now;
                }

                DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->update($attemptUpdate);

                if ($isPendingAttempt && Schema::hasTable('Payment_Gateway_Events_T')) {
                    $digest = hash('sha256', "admin-cancel|{$orderId}|{$attempt->id}");
                    if (!DB::table('Payment_Gateway_Events_T')->where('Payload_Digest', $digest)->exists()) {
                        DB::table('Payment_Gateway_Events_T')->insert([
                            'Payment_Gateway_Attempt_Id' => $attempt->id,
                            'Orders_Placed_Id' => $orderId,
                            'Gateway' => 'amwal_smartbox',
                            'Source' => 'admin',
                            'Payload_Digest' => $digest,
                            'Merchant_Reference' => $attempt->Merchant_Reference,
                            'Gateway_Transaction_Id' => null,
                            'Response_Code' => null,
                            'Outcome' => 'cancelled_before_payment',
                            'Processed_At' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            if ($loyaltyPoints > 0 && $loyalty) {
                DB::table('Customers_Loyalty_T')->where('id', $loyalty->id)->update([
                    'Points_Redeemed' => (int) $loyalty->Points_Redeemed - $loyaltyPoints,
                    'updated_at' => $now,
                ]);
                DB::table('Customers_Loyalty_Transactions_T')->insert([
                    'Loyalty_Transaction_Code' => $reversalCode,
                    'Customer_Id' => $order->Customers_Id,
                    'Orders_Placed_Id' => $orderId,
                    'Points_Earned' => 0,
                    'Points_Redeemed' => -$loyaltyPoints,
                    'Redeemed_Amount' => -abs((float) ($order->Loyalty_Discount_Amount ?? 0)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'idempotent' => false,
                'released_lines' => $activeDetails->count(),
                'released_loyalty_points' => $loyaltyPoints,
                'cart_restoration' => $cartRestoration,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function emptyCartRestoration(): array
    {
        return [
            'requested' => true,
            'performed' => true,
            'source' => 'admin',
            'restored_lines' => 0,
            'restored_quantity' => 0,
            'restored' => [],
            'skipped_lines' => 0,
            'skipped_quantity' => 0,
            'skipped' => [],
            'review_required' => false,
            'ignored_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function legacyCartRestoration(): array
    {
        $restoration = $this->emptyCartRestoration();
        $restoration['performed'] = false;
        $restoration['ignored_reason'] = 'order_already_cancelled';

        return $restoration;
    }

    /**
     * @param iterable<object> $payments
     * @param iterable<object> $attempts
     * @return array<string, mixed>|null
     */
    private function storedCartRestoration(iterable $payments, iterable $attempts): ?array
    {
        foreach ($payments as $payment) {
            $metadata = json_decode((string) ($payment->Payment_Metadata ?? ''), true);

            if (is_array($metadata) && is_array($metadata['cart_restoration'] ?? null)) {
                return $metadata['cart_restoration'];
            }
        }

        foreach ($attempts as $attempt) {
            $metadata = json_decode((string) ($attempt->Metadata ?? ''), true);

            if (is_array($metadata) && is_array($metadata['cart_restoration'] ?? null)) {
                return $metadata['cart_restoration'];
            }
        }

        return null;
    }
}
