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
     * @param list<array<string, mixed>> $lines Detail lines with per-line
     *        Commission_Type/Commission_Value snapshots (used to recompute
     *        'auto' commissions exactly; optional for backwards compatibility).
     *
     * @return array<string, mixed>
     */
    public static function vendorPlan(
        array $vendorOrder,
        string|int|float $refundedAmount,
        int $returnedQuantity,
        array $lines = [],
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
        $commissionUnits = self::adjustedCommissionUnits($vendorOrder, $subTotalUnits, $netSubTotalUnits, $lines);
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

    private static function adjustedCommissionUnits(array $vendorOrder, int $subTotalUnits, int $netSubTotalUnits, array $lines = []): int
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

        // 'auto' commissions were rolled up from per-line product commissions at
        // checkout; recompute exactly from the per-line snapshots when available
        // (fixed per-unit commissions scale with remaining quantity, percent with
        // the remaining line amount). Falls through to pro-rata when snapshots
        // are missing/invalid.
        if ($commissionType === 'auto') {
            $recomputedUnits = self::recomputedLineCommissionUnits($lines);

            if ($recomputedUnits !== null) {
                return min($recomputedUnits, $netSubTotalUnits);
            }
        }

        if ($subTotalUnits === 0) {
            return 0;
        }

        $ratio = $netSubTotalUnits / $subTotalUnits;

        return min((int) round($originalCommissionUnits * $ratio), $netSubTotalUnits);
    }

    /**
     * Recompute the vendor commission from per-line Commission_Type/Value
     * snapshots. Returns null when the lines cannot support an exact
     * recomputation (no lines, or any line missing a valid snapshot), so the
     * caller can fall back to pro-rata scaling.
     *
     * @param list<array<string, mixed>> $lines
     */
    private static function recomputedLineCommissionUnits(array $lines): ?int
    {
        if ($lines === []) {
            return null;
        }

        $totalUnits = 0;

        foreach ($lines as $line) {
            $type = strtolower((string) ($line['Commission_Type'] ?? ''));
            $value = (float) ($line['Commission_Value'] ?? 0);

            if ($value <= 0 || ! in_array($type, ['percent', 'fixed'], true)) {
                return null;
            }

            $orderedQuantity = max(0, (int) ($line['Quantity'] ?? 0));
            $returnedQuantity = max(0, (int) ($line['Returned_Quantity'] ?? 0));
            $remainingQuantity = max($orderedQuantity - $returnedQuantity, 0);

            $soldUnits = self::soldAmountUnits($line, max($orderedQuantity, 1));
            $refundedUnits = self::moneyToUnits($line['Refunded_Amount'] ?? 0, 'line refunded amount');
            $netUnits = array_key_exists('Net_Amount', $line) && $line['Net_Amount'] !== null
                ? self::moneyToUnits($line['Net_Amount'], 'line net amount')
                : max($soldUnits - $refundedUnits, 0);

            if ($type === 'percent') {
                $totalUnits += min((int) round($netUnits * ($value / 100)), $netUnits);

                continue;
            }

            // fixed: per-unit OMR amount times the remaining (unreturned) quantity,
            // never more than what is left on the line.
            $totalUnits += min((int) round($value * 1000) * $remainingQuantity, $netUnits);
        }

        return $totalUnits;
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
