<?php

namespace App\Support\Orders;

use InvalidArgumentException;

final class OrderItemLifecycle
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PACKED = 'packed';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_READY_FOR_COLLECTION = 'ready_for_collection';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_ON_HOLD = 'on-hold';

    public const RETURN_NOT_RETURNED = 'not_returned';
    public const RETURN_PARTIAL = 'partially_returned';
    public const RETURN_RETURNED = 'returned';

    public const REFUND_NOT_REFUNDED = 'not_refunded';
    public const REFUND_PARTIAL = 'partially_refunded';
    public const REFUND_REFUNDED = 'refunded';

    public const RESOLUTION_OPEN = 'open';
    public const RESOLUTION_PARTIAL = 'partially_adjusted';
    public const RESOLUTION_CLOSED = 'closed';

    /**
     * Status values currently allowed by the order-detail database constraint.
     *
     * These remain fulfillment states. Return and refund progress is tracked
     * separately so partial refunds do not destroy sales/order-flow reporting.
     *
     * @return list<string>
     */
    public static function fulfillmentStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PACKED,
            self::STATUS_DISPATCHED,
            self::STATUS_SHIPPED,
            self::STATUS_READY_FOR_COLLECTION,
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
            self::STATUS_RETURNED,
            self::STATUS_ON_HOLD,
        ];
    }

    /**
     * @return list<string>
     */
    public static function returnStates(): array
    {
        return [
            self::RETURN_NOT_RETURNED,
            self::RETURN_PARTIAL,
            self::RETURN_RETURNED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function refundStates(): array
    {
        return [
            self::REFUND_NOT_REFUNDED,
            self::REFUND_PARTIAL,
            self::REFUND_REFUNDED,
        ];
    }

    /**
     * @return array{
     *     ordered_quantity: int,
     *     returned_quantity: int,
     *     net_quantity: int,
     *     line_amount: string,
     *     refunded_amount: string,
     *     net_amount: string,
     *     return_state: string,
     *     refund_state: string,
     *     resolution_state: string
     * }
     */
    public static function snapshot(
        int $orderedQuantity,
        string|int|float $lineAmount,
        int $returnedQuantity,
        string|int|float $refundedAmount,
    ): array {
        self::assertNonNegativeQuantity('ordered quantity', $orderedQuantity);
        self::assertNonNegativeQuantity('returned quantity', $returnedQuantity);

        if ($orderedQuantity === 0) {
            throw new InvalidArgumentException('Ordered quantity must be greater than zero.');
        }

        if ($returnedQuantity > $orderedQuantity) {
            throw new InvalidArgumentException('Returned quantity cannot exceed ordered quantity.');
        }

        $lineAmountUnits = self::moneyToUnits($lineAmount, 'line amount');
        $refundedAmountUnits = self::moneyToUnits($refundedAmount, 'refunded amount');

        if ($refundedAmountUnits > $lineAmountUnits) {
            throw new InvalidArgumentException('Refunded amount cannot exceed line amount.');
        }

        $netAmountUnits = $lineAmountUnits - $refundedAmountUnits;

        return [
            'ordered_quantity' => $orderedQuantity,
            'returned_quantity' => $returnedQuantity,
            'net_quantity' => $orderedQuantity - $returnedQuantity,
            'line_amount' => self::unitsToMoney($lineAmountUnits),
            'refunded_amount' => self::unitsToMoney($refundedAmountUnits),
            'net_amount' => self::unitsToMoney($netAmountUnits),
            'return_state' => self::returnState($orderedQuantity, $returnedQuantity),
            'refund_state' => self::refundState($lineAmountUnits, $refundedAmountUnits),
            'resolution_state' => self::resolutionState(
                $orderedQuantity,
                $returnedQuantity,
                $lineAmountUnits,
                $refundedAmountUnits,
            ),
        ];
    }

    private static function returnState(int $orderedQuantity, int $returnedQuantity): string
    {
        if ($returnedQuantity === 0) {
            return self::RETURN_NOT_RETURNED;
        }

        return $returnedQuantity === $orderedQuantity
            ? self::RETURN_RETURNED
            : self::RETURN_PARTIAL;
    }

    private static function refundState(int $lineAmountUnits, int $refundedAmountUnits): string
    {
        if ($refundedAmountUnits === 0) {
            return self::REFUND_NOT_REFUNDED;
        }

        return $refundedAmountUnits === $lineAmountUnits
            ? self::REFUND_REFUNDED
            : self::REFUND_PARTIAL;
    }

    private static function resolutionState(
        int $orderedQuantity,
        int $returnedQuantity,
        int $lineAmountUnits,
        int $refundedAmountUnits,
    ): string {
        if ($returnedQuantity === 0 && $refundedAmountUnits === 0) {
            return self::RESOLUTION_OPEN;
        }

        if ($returnedQuantity === $orderedQuantity && $refundedAmountUnits === $lineAmountUnits) {
            return self::RESOLUTION_CLOSED;
        }

        return self::RESOLUTION_PARTIAL;
    }

    private static function assertNonNegativeQuantity(string $label, int $quantity): void
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException("The {$label} cannot be negative.");
        }
    }

    private static function moneyToUnits(string|int|float $amount, string $label): int
    {
        $normalized = trim((string) $amount);

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
