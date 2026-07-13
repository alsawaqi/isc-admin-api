<?php

namespace Tests\Unit\Orders;

use App\Models\OrdersPlaced;
use App\Support\Orders\ActualCommerceOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActualCommerceOrderTest extends TestCase
{
    #[DataProvider('visibilityCases')]
    public function test_it_keeps_payment_drafts_out_of_commerce_surfaces(
        ?string $method,
        ?string $status,
        bool $expected,
    ): void {
        $this->assertSame($expected, ActualCommerceOrder::includes($method, $status));
    }

    public static function visibilityCases(): array
    {
        return [
            'pending card draft' => ['card', 'pending', false],
            'failed card draft' => ['card', 'failed', false],
            'cancelled card tombstone' => ['card', 'cancelled', false],
            'captured card order' => ['card', 'paid', true],
            'late capture under review' => ['card', 'paid_requires_review', true],
            'refunded card order' => ['card', 'refunded', true],
            'bank transfer order' => ['transfer', 'pending', true],
            'cash on delivery order' => ['cod', 'pending', true],
            'legacy order' => [null, null, true],
        ];
    }

    public function test_eloquent_scope_uses_the_central_card_status_policy(): void
    {
        $query = OrdersPlaced::query()->actualCommerceOrder();

        $this->assertStringContainsString('Payment_Method', $query->toSql());
        $this->assertSame(
            ['card', ...ActualCommerceOrder::VISIBLE_CARD_PAYMENT_STATUSES],
            $query->getBindings(),
        );
    }
}
