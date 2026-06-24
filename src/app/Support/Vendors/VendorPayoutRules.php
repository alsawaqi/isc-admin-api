<?php

namespace App\Support\Vendors;

use InvalidArgumentException;

class VendorPayoutRules
{
    /**
     * @param array<string, mixed>|object $vendorOrder
     * @return array<string, mixed>
     */
    public static function paidPayload(array|object $vendorOrder, ?string $reference = null, mixed $payoutAt = null): array
    {
        $amount = self::payoutAmount($vendorOrder);

        return [
            'Payout_Amount' => $amount,
            'Payout_Status' => 'paid',
            'Payout_At' => $payoutAt,
            'Payout_Reference' => $reference,
        ];
    }

    /**
     * @param array<string, mixed>|object $vendorOrder
     */
    public static function payoutAmount(array|object $vendorOrder): string
    {
        $subtotal = self::number(
            self::firstPresent($vendorOrder, ['Net_Sub_Total', 'Sub_Total'])
        );
        $commission = self::number(
            self::firstPresent($vendorOrder, ['Adjusted_Commission_Amount', 'Commission_Amount'])
        );

        $amount = round($subtotal - $commission, 3);

        if ($amount < 0) {
            throw new InvalidArgumentException('Invalid payout amount (negative). Check subtotal/commission.');
        }

        return number_format($amount, 3, '.', '');
    }

    /**
     * @param array<string, mixed>|object $row
     * @param array<int, string> $keys
     */
    private static function firstPresent(array|object $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = self::value($row, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return 0;
    }

    private static function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed>|object $row
     */
    private static function value(array|object $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }
}
