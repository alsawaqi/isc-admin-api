<?php

namespace Tests\Unit\Products;

use App\Services\ProductHierarchyDisplayOrderService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProductHierarchyDisplayOrderServiceTest extends TestCase
{
    #[DataProvider('midpointCases')]
    public function test_midpoint_rank_only_returns_a_value_when_a_gap_exists(
        int $lower,
        int $upper,
        ?int $expected,
    ): void {
        $this->assertSame($expected, ProductHierarchyDisplayOrderService::midpointRank($lower, $upper));
    }

    public static function midpointCases(): array
    {
        return [
            'wide gap' => [1_000_000_000, 2_000_000_000, 1_500_000_000],
            'front gap' => [0, 1_000_000_000, 500_000_000],
            'adjacent ranks' => [10, 11, null],
            'reversed ranks' => [11, 10, null],
            'negative rank' => [-1, 10, null],
        ];
    }

    public function test_append_rank_uses_large_sparse_gaps(): void
    {
        $this->assertSame(
            ProductHierarchyDisplayOrderService::ORDER_STEP,
            ProductHierarchyDisplayOrderService::appendRank(null),
        );
        $this->assertSame(
            ProductHierarchyDisplayOrderService::ORDER_STEP * 2,
            ProductHierarchyDisplayOrderService::appendRank(ProductHierarchyDisplayOrderService::ORDER_STEP),
        );
    }

    public function test_append_rank_rejects_integer_overflow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);

        ProductHierarchyDisplayOrderService::appendRank(PHP_INT_MAX);
    }

    #[DataProvider('prefixSearchCases')]
    public function test_prefix_search_pattern_is_indexable_and_escapes_like_wildcards(
        string $search,
        string $expected,
    ): void {
        $pattern = ProductHierarchyDisplayOrderService::prefixSearchPattern($search);

        $this->assertSame($expected, $pattern);
        $this->assertStringEndsWith('%', $pattern);
        $this->assertFalse(str_starts_with($pattern, '%'));
    }

    public static function prefixSearchCases(): array
    {
        return [
            'plain text' => ['Air Compressor', 'Air Compressor%'],
            'trimmed code with sql wildcards' => ['  SUB_100%  ', 'SUB!_100!%%'],
            'escape character' => ['Safety!Tools', 'Safety!!Tools%'],
            'arabic prefix' => ['معدات', 'معدات%'],
        ];
    }
}
