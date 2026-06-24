<?php

namespace Tests\Unit\Orders;

use App\Support\Orders\OrderItemLifecycle;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrderItemLifecycleTest extends TestCase
{
    public function test_it_keeps_fulfillment_statuses_separate_from_return_and_refund_states(): void
    {
        $this->assertSame([
            'pending',
            'processing',
            'packed',
            'dispatched',
            'shipped',
            'ready_for_collection',
            'delivered',
            'cancelled',
            'returned',
            'on-hold',
        ], OrderItemLifecycle::fulfillmentStatuses());

        $this->assertSame([
            'not_returned',
            'partially_returned',
            'returned',
        ], OrderItemLifecycle::returnStates());

        $this->assertSame([
            'not_refunded',
            'partially_refunded',
            'refunded',
        ], OrderItemLifecycle::refundStates());
    }

    public function test_it_calculates_a_partial_return_and_refund_snapshot(): void
    {
        $snapshot = OrderItemLifecycle::snapshot(
            orderedQuantity: 3,
            lineAmount: '30.000',
            returnedQuantity: 1,
            refundedAmount: '10.000',
        );

        $this->assertSame([
            'ordered_quantity' => 3,
            'returned_quantity' => 1,
            'net_quantity' => 2,
            'line_amount' => '30.000',
            'refunded_amount' => '10.000',
            'net_amount' => '20.000',
            'return_state' => 'partially_returned',
            'refund_state' => 'partially_refunded',
            'resolution_state' => 'partially_adjusted',
        ], $snapshot);
    }

    public function test_it_supports_refund_without_returning_quantity(): void
    {
        $snapshot = OrderItemLifecycle::snapshot(
            orderedQuantity: 2,
            lineAmount: '25.500',
            returnedQuantity: 0,
            refundedAmount: '5.500',
        );

        $this->assertSame('not_returned', $snapshot['return_state']);
        $this->assertSame('partially_refunded', $snapshot['refund_state']);
        $this->assertSame(2, $snapshot['net_quantity']);
        $this->assertSame('20.000', $snapshot['net_amount']);
    }

    public function test_it_marks_a_fully_returned_and_refunded_line_as_closed(): void
    {
        $snapshot = OrderItemLifecycle::snapshot(
            orderedQuantity: 4,
            lineAmount: '12.000',
            returnedQuantity: 4,
            refundedAmount: '12.000',
        );

        $this->assertSame('returned', $snapshot['return_state']);
        $this->assertSame('refunded', $snapshot['refund_state']);
        $this->assertSame('closed', $snapshot['resolution_state']);
        $this->assertSame(0, $snapshot['net_quantity']);
        $this->assertSame('0.000', $snapshot['net_amount']);
    }

    public function test_it_rejects_adjustments_that_exceed_the_original_line(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderItemLifecycle::snapshot(
            orderedQuantity: 1,
            lineAmount: '9.000',
            returnedQuantity: 2,
            refundedAmount: '0.000',
        );
    }
}
