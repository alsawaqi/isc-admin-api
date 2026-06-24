<?php

namespace App\Support\Orders;

use InvalidArgumentException;

final class OrderReturnRefundCalculator
{
    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    public static function linePlan(
        array $line,
        int $returnQuantity,
        string|int|float $refundAmount,
        bool $restock,
    ): array {
        if ($returnQuantity < 0) {
            throw new InvalidArgumentException('Return quantity cannot be negative.');
        }

        $refundUnits = self::moneyToUnits($refundAmount, 'refund amount');

        if ($returnQuantity === 0 && $refundUnits === 0) {
            throw new InvalidArgumentException('A return/refund action needs a quantity, a refund amount, or both.');
        }

        $orderedQuantity = max(0, (int) ($line['Quantity'] ?? 0));

        if ($orderedQuantity === 0) {
            throw new InvalidArgumentException('Order line quantity must be greater than zero.');
        }

        $previousReturnedQuantity = max(0, (int) ($line['Returned_Quantity'] ?? 0));
        $previousRefundUnits = self::moneyToUnits($line['Refunded_Amount'] ?? 0, 'previous refunded amount');
        $soldUnits = self::soldAmountUnits($line, $orderedQuantity);

        if ($previousReturnedQuantity + $returnQuantity > $orderedQuantity) {
            throw new InvalidArgumentException('Return quantity cannot exceed the remaining order line quantity.');
        }

        if ($previousRefundUnits + $refundUnits > $soldUnits) {
            throw new InvalidArgumentException('Refund amount cannot exceed the remaining order line amount.');
        }

        $returnedQuantity = $previousReturnedQuantity + $returnQuantity;
        $refundedUnits = $previousRefundUnits + $refundUnits;

        $snapshot = OrderItemLifecycle::snapshot(
            orderedQuantity: $orderedQuantity,
            lineAmount: self::unitsToMoney($soldUnits),
            returnedQuantity: $returnedQuantity,
            refundedAmount: self::unitsToMoney($refundedUnits),
        );

        $currentStatus = (string) ($line['Status'] ?? OrderItemLifecycle::STATUS_DELIVERED);

        return [
            'ordered_quantity' => $orderedQuantity,
            'previous_returned_quantity' => $previousReturnedQuantity,
            'return_quantity' => $returnQuantity,
            'returned_quantity' => $returnedQuantity,
            'previous_refunded_amount' => self::unitsToMoney($previousRefundUnits),
            'refund_amount' => self::unitsToMoney($refundUnits),
            'refunded_amount' => self::unitsToMoney($refundedUnits),
            'sold_amount' => self::unitsToMoney($soldUnits),
            'net_amount' => $snapshot['net_amount'],
            'net_quantity' => $snapshot['net_quantity'],
            'return_state' => $snapshot['return_state'],
            'refund_state' => $snapshot['refund_state'],
            'resolution_state' => $snapshot['resolution_state'],
            'restock_quantity' => $restock ? $returnQuantity : 0,
            'next_status' => $snapshot['return_state'] === OrderItemLifecycle::RETURN_RETURNED
                ? OrderItemLifecycle::STATUS_RETURNED
                : $currentStatus,
            'adjustment_type' => self::adjustmentType($returnQuantity, $refundUnits),
        ];
    }

    /**
     * @param array<string, mixed> $vendorOrder
     *
     * @return array<string, mixed>
     */
    public static function vendorPlan(
        array $vendorOrder,
        string|int|float $refundedAmount,
        int $returnedQuantity,
    ): array {
        if ($returnedQuantity < 0) {
            throw new InvalidArgumentException('Returned quantity cannot be negative.');
        }

        $subTotalUnits = self::moneyToUnits($vendorOrder['Sub_Total'] ?? 0, 'vendor subtotal');
        $refundedUnits = self::moneyToUnits($refundedAmount, 'vendor refunded amount');

        if ($refundedUnits > $subTotalUnits) {
            throw new InvalidArgumentException('Vendor refunded amount cannot exceed vendor subtotal.');
        }

        $netSubTotalUnits = $subTotalUnits - $refundedUnits;
        $commissionUnits = self::adjustedCommissionUnits($vendorOrder, $subTotalUnits, $netSubTotalUnits);
        $netPayoutUnits = max($netSubTotalUnits - $commissionUnits, 0);

        $originalCommissionUnits = self::moneyToUnits($vendorOrder['Commission_Amount'] ?? 0, 'vendor commission amount');
        $originalPayoutUnits = self::moneyToUnits(
            $vendorOrder['Payout_Amount'] ?? self::unitsToMoney(max($subTotalUnits - $originalCommissionUnits, 0)),
            'vendor payout amount',
        );

        return [
            'returned_quantity' => $returnedQuantity,
            'refunded_amount' => self::unitsToMoney($refundedUnits),
            'net_sub_total' => self::unitsToMoney($netSubTotalUnits),
            'adjusted_commission_amount' => self::unitsToMoney($commissionUnits),
            'net_payout_amount' => self::unitsToMoney($netPayoutUnits),
            'payout_adjustment_amount' => self::unitsToMoney(max($originalPayoutUnits - $netPayoutUnits, 0)),
        ];
    }

    private static function soldAmountUnits(array $line, int $orderedQuantity): int
    {
        foreach (['Sold_Amount', 'Subtotal'] as $key) {
            if (array_key_exists($key, $line) && self::moneyToUnits($line[$key] ?? 0, $key) > 0) {
                return self::moneyToUnits($line[$key], $key);
            }
        }

        $unitPrice = self::moneyToUnits($line['Price'] ?? 0, 'line price');

        return $unitPrice * $orderedQuantity;
    }

    private static function adjustmentType(int $returnQuantity, int $refundUnits): string
    {
        if ($returnQuantity > 0 && $refundUnits > 0) {
            return 'return_and_refund';
        }

        if ($returnQuantity > 0) {
            return 'return';
        }

        return 'refund';
    }

    private static function adjustedCommissionUnits(array $vendorOrder, int $subTotalUnits, int $netSubTotalUnits): int
    {
        $commissionType = strtolower((string) ($vendorOrder['Commission_Type'] ?? ''));
        $commissionValue = (float) ($vendorOrder['Commission_Value'] ?? 0);
        $originalCommissionUnits = self::moneyToUnits($vendorOrder['Commission_Amount'] ?? 0, 'vendor commission amount');

        if ($netSubTotalUnits === 0) {
            return 0;
        }

        if ($commissionType === 'percent' && $commissionValue > 0) {
            return min((int) round($netSubTotalUnits * ($commissionValue / 100)), $netSubTotalUnits);
        }

        if ($subTotalUnits === 0) {
            return 0;
        }

        $ratio = $netSubTotalUnits / $subTotalUnits;

        return min((int) round($originalCommissionUnits * $ratio), $netSubTotalUnits);
    }

    private static function moneyToUnits(string|int|float|null $amount, string $label): int
    {
        $normalized = trim((string) ($amount ?? 0));

        if ($normalized === '' || ! is_numeric($normalized)) {
            throw new InvalidArgumentException("The {$label} must be numeric.");
        }

        $units = (int) round(((float) $normalized) * 1000);

        if ($units < 0) {
            throw new InvalidArgumentException("The {$label} cannot be negative.");
        }

        return $units;
    }

    private static function unitsToMoney(int $units): string
    {
        return number_format($units / 1000, 3, '.', '');
    }
}
