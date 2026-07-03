<?php

namespace Tests\Unit\Pricing;

use App\Support\Pricing\BulkPriceRules;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests (no app boot, no DB) for the quantity-tier bulk price rules.
 */
class BulkPriceRulesTest extends TestCase
{
    // -----------------------------------------------------------------
    // validateSet — happy paths
    // -----------------------------------------------------------------

    public function test_empty_set_is_valid(): void
    {
        $this->assertSame([], BulkPriceRules::validateSet([]));
    }

    public function test_valid_set_with_gap_and_open_ended_tier(): void
    {
        $tiers = [
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
            ['min_qty' => 20, 'max_qty' => 50, 'unit_price' => 5.5],
            ['min_qty' => 51, 'max_qty' => null, 'unit_price' => 5.0],
        ];

        $this->assertSame([], BulkPriceRules::validateSet($tiers));
    }

    public function test_adjacent_ranges_do_not_overlap(): void
    {
        $tiers = [
            ['min_qty' => 1, 'max_qty' => 5, 'unit_price' => 9.0],
            ['min_qty' => 6, 'max_qty' => 10, 'unit_price' => 8.0],
        ];

        $this->assertSame([], BulkPriceRules::validateSet($tiers));
    }

    public function test_house_column_keys_are_accepted(): void
    {
        $tiers = [
            ['Min_Qty' => 5, 'Max_Qty' => 10, 'Unit_Price' => 6.0],
        ];

        $this->assertSame([], BulkPriceRules::validateSet($tiers));
        $this->assertSame(6.0, BulkPriceRules::resolveUnitPrice($tiers, 7));
    }

    public function test_single_quantity_tier_min_equals_max(): void
    {
        $this->assertSame([], BulkPriceRules::validateSet([
            ['min_qty' => 5, 'max_qty' => 5, 'unit_price' => 6.0],
        ]));
    }

    // -----------------------------------------------------------------
    // validateSet — per-tier rules
    // -----------------------------------------------------------------

    public function test_min_qty_below_one_is_rejected(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 0, 'max_qty' => 10, 'unit_price' => 6.0],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('minimum quantity', $errors[0]);
    }

    public function test_max_below_min_is_rejected(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 10, 'max_qty' => 5, 'unit_price' => 6.0],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('maximum quantity (5)', $errors[0]);
    }

    public function test_zero_or_negative_unit_price_is_rejected(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 0],
            ['min_qty' => 20, 'max_qty' => 30, 'unit_price' => -1],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('greater than 0', $errors[0]);
    }

    public function test_non_numeric_values_are_rejected(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 'abc', 'max_qty' => 10, 'unit_price' => 6.0],
            ['min_qty' => 5, 'max_qty' => 'xyz', 'unit_price' => 6.0],
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 'oops'],
        ]);

        $this->assertCount(3, $errors);
    }

    // -----------------------------------------------------------------
    // validateSet — floor
    // -----------------------------------------------------------------

    public function test_price_below_floor_is_rejected(): void
    {
        $errors = BulkPriceRules::validateSet(
            [['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 4.5]],
            5.0
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('minimum selling price (5.000)', $errors[0]);
    }

    public function test_price_equal_to_floor_is_allowed(): void
    {
        $this->assertSame([], BulkPriceRules::validateSet(
            [['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 5.0]],
            5.0
        ));
    }

    public function test_null_floor_means_no_floor_check(): void
    {
        $this->assertSame([], BulkPriceRules::validateSet(
            [['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 0.001]],
            null
        ));
    }

    // -----------------------------------------------------------------
    // validateSet — overlaps
    // -----------------------------------------------------------------

    public function test_duplicate_range_is_reported_as_overlap(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 5.5],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('Range 5-10 overlaps existing range 5-10.', $errors[0]);
    }

    public function test_partial_overlap_is_rejected_and_names_the_clash(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
            ['min_qty' => 10, 'max_qty' => 20, 'unit_price' => 5.5],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('Range 10-20 overlaps existing range 5-10.', $errors[0]);
    }

    public function test_open_ended_tier_overlaps_everything_above_its_min(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 20, 'max_qty' => null, 'unit_price' => 5.0],
            ['min_qty' => 30, 'max_qty' => 40, 'unit_price' => 4.5],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('Range 30-40 overlaps existing range 20+.', $errors[0]);
    }

    public function test_two_open_ended_tiers_always_overlap(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 20, 'max_qty' => null, 'unit_price' => 5.0],
            ['min_qty' => 100, 'max_qty' => null, 'unit_price' => 4.0],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('Range 100+ overlaps existing range 20+.', $errors[0]);
    }

    public function test_range_fully_containing_another_is_an_overlap(): void
    {
        $errors = BulkPriceRules::validateSet([
            ['min_qty' => 1, 'max_qty' => 100, 'unit_price' => 6.0],
            ['min_qty' => 10, 'max_qty' => 20, 'unit_price' => 5.0],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('overlaps', $errors[0]);
    }

    // -----------------------------------------------------------------
    // resolveUnitPrice
    // -----------------------------------------------------------------

    private function tiers(): array
    {
        return [
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
            ['min_qty' => 20, 'max_qty' => 50, 'unit_price' => 5.5],
            ['min_qty' => 51, 'max_qty' => null, 'unit_price' => 5.0],
        ];
    }

    public function test_resolve_returns_null_below_all_tiers(): void
    {
        $this->assertNull(BulkPriceRules::resolveUnitPrice($this->tiers(), 4));
    }

    public function test_resolve_matches_boundaries_inclusively(): void
    {
        $this->assertSame(6.0, BulkPriceRules::resolveUnitPrice($this->tiers(), 5));
        $this->assertSame(6.0, BulkPriceRules::resolveUnitPrice($this->tiers(), 10));
        $this->assertSame(5.5, BulkPriceRules::resolveUnitPrice($this->tiers(), 20));
        $this->assertSame(5.5, BulkPriceRules::resolveUnitPrice($this->tiers(), 50));
    }

    public function test_resolve_returns_null_in_gaps(): void
    {
        $this->assertNull(BulkPriceRules::resolveUnitPrice($this->tiers(), 11));
        $this->assertNull(BulkPriceRules::resolveUnitPrice($this->tiers(), 19));
    }

    public function test_resolve_open_ended_tier_matches_any_large_quantity(): void
    {
        $this->assertSame(5.0, BulkPriceRules::resolveUnitPrice($this->tiers(), 51));
        $this->assertSame(5.0, BulkPriceRules::resolveUnitPrice($this->tiers(), 100000));
    }

    public function test_resolve_empty_set_returns_null(): void
    {
        $this->assertNull(BulkPriceRules::resolveUnitPrice([], 10));
    }

    // -----------------------------------------------------------------
    // rangeLabel
    // -----------------------------------------------------------------

    public function test_range_label_formats(): void
    {
        $this->assertSame('5-10', BulkPriceRules::rangeLabel(5, 10));
        $this->assertSame('51+', BulkPriceRules::rangeLabel(51, null));
    }
}
