<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class OfflinePaymentConfirmationService
{
    /**
     * Confirm actual COD collection or bank-transfer receipt and award the
     * order's loyalty points exactly once.
     *
     * @param  array{url?: string|null, mime?: string|null}  $signature
     * @return array{method: string, payment_status: string, points_awarded: int, points_previously_awarded: bool, idempotent: bool}
     */
    public function confirm(
        int $orderId,
        int $actorId,
        string $actorName,
        ?string $actorRole,
        string $note,
        ?string $transferReference,
        array $signature,
    ): array {
        return DB::transaction(function () use (
            $orderId,
            $actorId,
            $actorName,
            $actorRole,
            $note,
            $transferReference,
            $signature,
        ) {
            $order = DB::table('Orders_Placed_T')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new NotFoundHttpException('Order not found.');
            }

            $method = strtolower(trim((string) ($order->Payment_Method ?? '')));
            if (! in_array($method, ['cod', 'transfer'], true)) {
                throw new ConflictHttpException('Only COD and bank-transfer orders can be confirmed here.');
            }

            if (in_array(strtolower((string) ($order->Status ?? '')), ['cancelled', 'returned'], true)) {
                throw new ConflictHttpException('A cancelled or returned order cannot be marked paid.');
            }

            if ($method === 'cod'
                && strtolower((string) ($order->Status ?? '')) !== 'delivered') {
                throw new ConflictHttpException(
                    'COD can be confirmed only after the order is handed over or delivered.',
                );
            }

            if (Schema::hasTable('Orders_Placed_Details_T')) {
                $orderDetails = DB::table('Orders_Placed_Details_T')
                    ->where('Orders_Placed_Id', $orderId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $hasUnreconciledAdjustment = $orderDetails->contains(
                    fn ($detail) => in_array(
                        strtolower((string) ($detail->Status ?? '')),
                        ['cancelled', 'returned', 'refunded', 'partially_refunded'],
                        true,
                    )
                        || in_array(
                            strtolower((string) ($detail->Return_State ?? '')),
                            ['partially_returned', 'returned'],
                            true,
                        )
                        || in_array(
                            strtolower((string) ($detail->Refund_State ?? '')),
                            ['partially_refunded', 'refunded'],
                            true,
                        )
                        || (int) ($detail->Returned_Quantity ?? 0) > 0
                        || (float) ($detail->Refunded_Amount ?? 0) > 0,
                );

                if ($hasUnreconciledAdjustment) {
                    throw new ConflictHttpException(
                        'This order has cancelled or refunded lines and its payment total must be reconciled first.',
                    );
                }
            }

            $headerIds = DB::table('Sales_Transaction_Header_T')
                ->where('Orders_Placed_Id', $orderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($headerIds->isEmpty()) {
                throw new ConflictHttpException('The order payment record is missing.');
            }

            $payments = DB::table('Sales_Transactions_Details_T')
                ->whereIn('Sales_Transaction_Header_Id', $headerIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                throw new ConflictHttpException('The order payment record is missing.');
            }

            foreach ($payments as $payment) {
                if (strtolower(trim((string) ($payment->Payment_Method ?? ''))) !== $method) {
                    throw new ConflictHttpException('The order and payment methods do not match.');
                }

                if (strtolower(trim((string) ($payment->Payment_Gateway ?? ''))) === 'amwal_smartbox') {
                    throw new ConflictHttpException('AmwalPay card payments must be confirmed by the gateway.');
                }

                if (strtoupper(trim((string) ($payment->Payment_Currency ?? 'OMR'))) !== 'OMR') {
                    throw new ConflictHttpException('The offline payment currency is not OMR.');
                }
            }

            $expectedUnits = $this->moneyToUnits($order->Total_Price ?? null);
            $paymentUnits = 0;
            foreach ($payments as $payment) {
                $units = $this->moneyToUnits($payment->Payment_Amount ?? null);
                if ($units === null) {
                    throw new ConflictHttpException('The stored payment amount is invalid.');
                }
                $paymentUnits += $units;
            }

            if ($expectedUnits === null || $expectedUnits <= 0 || $paymentUnits !== $expectedUnits) {
                throw new ConflictHttpException('The payment amount does not match the order total.');
            }

            $orderIsPaid = strtolower((string) ($order->Payment_Status ?? '')) === 'paid';
            $paymentsArePaid = $payments->every(
                fn ($payment) => strtolower((string) ($payment->Payment_Status ?? '')) === 'paid',
            );

            if ($orderIsPaid !== $paymentsArePaid) {
                throw new ConflictHttpException('The order and payment statuses require reconciliation.');
            }

            $alreadyPaid = $orderIsPaid && $paymentsArePaid;
            $confirmedAt = now();

            if (! $alreadyPaid) {
                $allowedPendingStatuses = ['', 'unpaid', 'pending', 'pending_verification', 'confirmed'];
                if (! in_array(
                    strtolower(trim((string) ($order->Payment_Status ?? ''))),
                    $allowedPendingStatuses,
                    true,
                )) {
                    throw new ConflictHttpException(
                        'This order payment cannot be confirmed from its current status.',
                    );
                }

                foreach ($payments as $payment) {
                    if (! in_array(strtolower(trim((string) ($payment->Payment_Status ?? ''))), $allowedPendingStatuses, true)) {
                        throw new ConflictHttpException('This offline payment cannot be confirmed from its current status.');
                    }
                }

                if ($method === 'transfer') {
                    $confirmedReference = trim((string) $transferReference);
                    if ($confirmedReference === '') {
                        throw new UnprocessableEntityHttpException(
                            'A verified bank-transfer reference is required.',
                        );
                    }

                    DB::table('Sales_Transactions_Details_T')
                        ->whereIn('id', $payments->pluck('id')->all())
                        ->update([
                            'Payment_Status' => 'paid',
                            'Transfer_Reference' => $confirmedReference,
                            'Transfer_Received_At' => $confirmedAt,
                            'updated_at' => $confirmedAt,
                        ]);
                } else {
                    DB::table('Sales_Transactions_Details_T')
                        ->whereIn('id', $payments->pluck('id')->all())
                        ->update([
                            'Payment_Status' => 'paid',
                            'COD_Collected' => 1,
                            'COD_Collected_At' => $confirmedAt,
                            'COD_Note' => $note,
                            'updated_at' => $confirmedAt,
                        ]);
                }

                DB::table('Orders_Placed_T')->where('id', $orderId)->update([
                    'Payment_Status' => 'paid',
                    'updated_at' => $confirmedAt,
                ]);
            }

            $earning = $this->awardLoyaltyPoints($order, $expectedUnits, $confirmedAt);
            $performedSettlement = ! $alreadyPaid || ! $earning['points_previously_awarded'];

            if ($performedSettlement && Schema::hasTable('Order_Process_Log_T')) {
                DB::table('Order_Process_Log_T')->insert([
                    'Orders_Placed_Id' => $orderId,
                    'Step_Code' => 'OFFLINE_PAYMENT_CONFIRMED',
                    'Status' => 'paid',
                    'Is_External' => false,
                    'Actor_User_Id' => $actorId > 0 ? $actorId : null,
                    'Actor_Name' => $actorName,
                    'Actor_Role' => $actorRole,
                    'Signed_At' => $confirmedAt,
                    'Signature_Url' => $signature['url'] ?? null,
                    'Signature_Mime' => $signature['mime'] ?? null,
                    'Notes' => ($method === 'cod' ? 'COD collected. ' : 'Bank transfer received. ').$note,
                    'created_at' => $confirmedAt,
                    'updated_at' => $confirmedAt,
                ]);
            }

            return [
                'method' => $method,
                'payment_status' => 'paid',
                'points_awarded' => $earning['points_awarded'],
                'points_previously_awarded' => $earning['points_previously_awarded'],
                'idempotent' => ! $performedSettlement,
            ];
        }, 3);
    }

    /** @return array{points_awarded: int, points_previously_awarded: bool} */
    private function awardLoyaltyPoints(object $order, int $orderTotalUnits, $confirmedAt): array
    {
        if (! Schema::hasTable('Customers_Loyalty_T')
            || ! Schema::hasTable('Customers_Loyalty_Transactions_T')) {
            throw new ConflictHttpException('Loyalty settlement is not available.');
        }

        $existingEarn = DB::table('Customers_Loyalty_Transactions_T')
            ->where('Orders_Placed_Id', $order->id)
            ->where('Points_Earned', '>', 0)
            ->lockForUpdate()
            ->first();

        $earningCode = 'LOY_EARN_'.$order->id;
        $existingMarker = DB::table('Customers_Loyalty_Transactions_T')
            ->where('Loyalty_Transaction_Code', $earningCode)
            ->lockForUpdate()
            ->first();

        if ($existingEarn || $existingMarker) {
            return [
                'points_awarded' => 0,
                'points_previously_awarded' => true,
            ];
        }

        DB::table('Customers_Master_T')
            ->where('id', $order->Customers_Id)
            ->lockForUpdate()
            ->first();

        $loyalty = DB::table('Customers_Loyalty_T')
            ->where('Customer_Id', $order->Customers_Id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $loyalty) {
            $loyaltyId = DB::table('Customers_Loyalty_T')->insertGetId([
                'Customers_Loyalty_Code' => 'LOY_BAL_'.$order->Customers_Id,
                'Customer_Id' => $order->Customers_Id,
                'Points_Earned' => 0,
                'Points_Redeemed' => 0,
                'created_at' => $confirmedAt,
                'updated_at' => $confirmedAt,
            ]);
            $loyalty = DB::table('Customers_Loyalty_T')->where('id', $loyaltyId)->first();
        }

        $settings = Schema::hasTable('System_Parameter_Loyalty_Points_T')
            ? DB::table('System_Parameter_Loyalty_Points_T')->first()
            : null;
        $earnAmount = (float) ($settings->Earn_Amount ?? 0);
        $earnPoints = (float) ($settings->Earn_Points ?? $settings->Point ?? 0);
        $points = $earnAmount > 0 && $earnPoints > 0
            ? (int) round(($earnPoints / $earnAmount) * ($orderTotalUnits / 1000))
            : 0;

        if ($points > 0) {
            DB::table('Customers_Loyalty_T')->where('id', $loyalty->id)->update([
                'Points_Earned' => DB::raw('COALESCE(Points_Earned, 0) + '.(int) $points),
                'updated_at' => $confirmedAt,
            ]);
        }

        $ledger = [
            'Loyalty_Transaction_Code' => $earningCode,
            'Customer_Id' => $order->Customers_Id,
            'Orders_Placed_Id' => $order->id,
            'Points_Earned' => $points,
            'Points_Redeemed' => 0,
            'created_at' => $confirmedAt,
            'updated_at' => $confirmedAt,
        ];
        if (Schema::hasColumn('Customers_Loyalty_Transactions_T', 'Redeemed_Amount')) {
            $ledger['Redeemed_Amount'] = 0;
        }
        DB::table('Customers_Loyalty_Transactions_T')->insert($ledger);

        return [
            'points_awarded' => $points,
            'points_previously_awarded' => false,
        ];
    }

    private function moneyToUnits(mixed $amount): ?int
    {
        if (is_float($amount)) {
            if (! is_finite($amount) || $amount < 0) {
                return null;
            }
            $amount = number_format($amount, 3, '.', '');
        }

        $value = trim((string) $amount);
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,3}))?$/', $value, $matches)) {
            return null;
        }

        return ((int) $matches[1] * 1000)
            + (int) str_pad($matches[2] ?? '', 3, '0');
    }
}
