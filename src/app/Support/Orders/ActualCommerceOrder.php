<?php

namespace App\Support\Orders;

final class ActualCommerceOrder
{
    /** @var list<string> */
    public const VISIBLE_CARD_PAYMENT_STATUSES = [
        'paid',
        'paid_requires_review',
        'refunded',
        'partially_refunded',
    ];

    public static function includes(?string $paymentMethod, ?string $paymentStatus): bool
    {
        return strtolower(trim((string) $paymentMethod)) !== 'card'
            || in_array(
                strtolower(trim((string) $paymentStatus)),
                self::VISIBLE_CARD_PAYMENT_STATUSES,
                true,
            );
    }
}
