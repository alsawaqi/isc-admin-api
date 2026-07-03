<?php

namespace App\Support\Pricing;

/**
 * Pure quantity-tier bulk-price rules (no framework, no DB) so they can be
 * unit-tested and mirrored verbatim across the ISC backends.
 *
 * Tier shape (array): ['min_qty' => int, 'max_qty' => int|null, 'unit_price' => float]
 * - min_qty >= 1
 * - max_qty NULL = open-ended ("and above"); when present must be >= min_qty
 * - unit_price > 0 and, when the product has a non-null Minimum_Selling_Price
 *   floor, unit_price >= floor
 * - NO overlapping ranges across the set (null max = infinity; duplicates are
 *   overlaps; at most one open-ended tier — a second always overlaps)
 * - Gaps are fine: quantities not covered pay the normal price.
 */
class BulkPriceRules
{
    /**
     * Validate a full replace-set of tiers against the smart rules.
     *
     * Accepts snake_case (min_qty/max_qty/unit_price) or house-column
     * (Min_Qty/Max_Qty/Unit_Price) keys.
     *
     * @param  array      $tiers array of tier arrays (may be empty = clear all)
     * @param  float|null $floor product Minimum_Selling_Price (null = no floor)
     * @return string[]   error messages; empty array = valid
     */
    public static function validateSet(array $tiers, ?float $floor = null): array
    {
        $errors = [];
        $normalized = [];

        foreach (array_values($tiers) as $i => $tier) {
            $label = 'Tier ' . ($i + 1);

            if (!is_array($tier)) {
                $errors[] = "{$label}: invalid tier payload.";
                continue;
            }

            $min = self::value($tier, 'min_qty', 'Min_Qty');
            $max = self::value($tier, 'max_qty', 'Max_Qty');
            $price = self::value($tier, 'unit_price', 'Unit_Price');

            if (!is_numeric($min) || (int) $min != $min || (int) $min < 1) {
                $errors[] = "{$label}: minimum quantity must be an integer of at least 1.";
                continue;
            }

            $min = (int) $min;

            if ($max !== null && $max !== '') {
                if (!is_numeric($max) || (int) $max != $max) {
                    $errors[] = "{$label}: maximum quantity must be an integer or empty (and above).";
                    continue;
                }

                $max = (int) $max;

                if ($max < $min) {
                    $errors[] = "{$label}: maximum quantity ({$max}) must be greater than or equal to the minimum quantity ({$min}).";
                    continue;
                }
            } else {
                $max = null;
            }

            if (!is_numeric($price) || (float) $price <= 0) {
                $errors[] = "{$label}: unit price must be greater than 0.";
                continue;
            }

            $price = (float) $price;

            if ($floor !== null && $price < $floor) {
                $errors[] = sprintf(
                    '%s: unit price (%s) cannot be below the minimum selling price (%s).',
                    $label,
                    number_format($price, 3, '.', ''),
                    number_format($floor, 3, '.', '')
                );
                continue;
            }

            $normalized[] = ['min' => $min, 'max' => $max, 'label' => self::rangeLabel($min, $max)];
        }

        // Overlap check across every pair (null max treated as infinity).
        $count = count($normalized);
        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                $aMax = $normalized[$a]['max'] ?? PHP_INT_MAX;
                $bMax = $normalized[$b]['max'] ?? PHP_INT_MAX;

                if ($normalized[$a]['min'] <= $bMax && $normalized[$b]['min'] <= $aMax) {
                    $errors[] = sprintf(
                        'Range %s overlaps existing range %s.',
                        $normalized[$b]['label'],
                        $normalized[$a]['label']
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve the tier unit price for a quantity, or null when no tier covers
     * it (normal pricing applies). Assumes a validated (non-overlapping) set,
     * so at most one tier can match.
     *
     * @param array $tiers array of tier arrays (snake_case or house-column keys)
     */
    public static function resolveUnitPrice(array $tiers, int $qty): ?float
    {
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $min = self::value($tier, 'min_qty', 'Min_Qty');
            $max = self::value($tier, 'max_qty', 'Max_Qty');
            $price = self::value($tier, 'unit_price', 'Unit_Price');

            if (!is_numeric($min) || !is_numeric($price)) {
                continue;
            }

            $matchesMax = ($max === null || $max === '') || (is_numeric($max) && $qty <= (int) $max);

            if ($qty >= (int) $min && $matchesMax) {
                return (float) $price;
            }
        }

        return null;
    }

    /**
     * Human range label: "5-10" or "51+" for open-ended tiers.
     */
    public static function rangeLabel(int $min, ?int $max): string
    {
        return $max === null ? "{$min}+" : "{$min}-{$max}";
    }

    /**
     * Read a tier value by snake_case key with house-column fallback.
     */
    private static function value(array $tier, string $snake, string $column)
    {
        if (array_key_exists($snake, $tier)) {
            return $tier[$snake];
        }

        return $tier[$column] ?? null;
    }
}
