<?php

namespace Tests\Unit\Products;

use App\Http\Controllers\ProductDepartmentsController;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;

final class ProductDepartmentsIndexOrderingTest extends TestCase
{
    public function test_id_sort_is_not_added_twice(): void
    {
        $query = new RecordingEloquentBuilder;
        (new TestableProductDepartmentsController)->applyIndexOrderingForTest($query, 'id', 'desc');

        $this->assertSame([
            ['id', 'desc'],
        ], $query->orderings);
    }

    public function test_non_id_sort_keeps_id_as_a_deterministic_tie_breaker(): void
    {
        $query = new RecordingEloquentBuilder;
        (new TestableProductDepartmentsController)->applyIndexOrderingForTest($query, 'Display_Order', 'asc');

        $this->assertSame([
            ['Display_Order', 'asc'],
            ['id', 'asc'],
        ], $query->orderings);
    }
}

final class TestableProductDepartmentsController extends ProductDepartmentsController
{
    public function applyIndexOrderingForTest(Builder $query, string $sortBy, string $sortDir): void
    {
        $this->applyIndexOrdering($query, $sortBy, $sortDir);
    }
}

final class RecordingEloquentBuilder extends Builder
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $orderings = [];

    public function __construct() {}

    public function orderBy($column, $direction = 'asc'): static
    {
        $this->orderings[] = [(string) $column, (string) $direction];

        return $this;
    }
}
