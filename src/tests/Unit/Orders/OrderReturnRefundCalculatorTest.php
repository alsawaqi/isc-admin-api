<?php

namespace Tests\Unit\Orders;

use App\Support\Orders\OrderReturnRefundCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrderReturnRefundCalculatorTest extends TestCase
{
    public function test_it_plans_partial_return_refund_and_restock_for_a_line_item(): void
    {
        $plan = OrderReturnRefundCalculator::linePlan(
            line: [
                'Quantity' => 5,
                'Returned_Quantity' => 1,
                'Refunded_Amount' => '3.000',
                'Sold_Amount' => '50.000',
                'Status' => 'delivered',
            ],
            returnQuantity: 2,
            refundAmount: '10.000',
            restock: true,
        );

        $this->assertSame(3, $plan['returned_quantity']);
        $this->assertSame('13.000', $plan['refunded_amount']);
        $this->assertSame('37.000', $plan['net_amount']);
        $this->assertSame('partially_returned', $plan['return_state']);
        $this->assertSame('partially_refunded', $plan['refund_state']);
        $this->assertSame('partially_adjusted', $plan['resolution_state']);
        $this->assertSame(2, $plan['restock_quantity']);
        $this->assertSame('delivered', $plan['next_status']);
        $this->assertSame('return_and_refund', $plan['adjustment_type']);
    }

    public function test_it_supports_refund_without_returning_or_restocking_stock(): void
    {
        $plan = OrderReturnRefundCalculator::linePlan(
            line: [
                'Quantity' => 2,
                'Returned_Quantity' => 0,
                'Refunded_Amount' => '0.000',
                'Subtotal' => '25.500',
                'Status' => 'delivered',
            ],
            returnQuantity: 0,
            refundAmount: '5.500',
            restock: true,
        );

        $this->assertSame(0, $plan['returned_quantity']);
        $this->assertSame('5.500', $plan['refunded_amount']);
        $this->assertSame(0, $plan['restock_quantity']);
        $this->assertSame('not_returned', $plan['return_state']);
        $this->assertSame('partially_refunded', $plan['refund_state']);
        $this->assertSame('refund', $plan['adjustment_type']);
    }

    public function test_it_closes_a_line_when_the_full_quantity_and_amount_are_adjusted(): void
    {
        $plan = OrderReturnRefundCalculator::linePlan(
            line: [
                'Quantity' => 2,
                'Returned_Quantity' => 0,
                'Refunded_Amount' => '0.000',
                'Price' => '15.000',
                'Status' => 'delivered',
            ],
            returnQuantity: 2,
            refundAmount: '30.000',
            restock: false,
        );

        $this->assertSame('returned', $plan['return_state']);
        $this->assertSame('refunded', $plan['refund_state']);
        $this->assertSame('closed', $plan['resolution_state']);
        $this->assertSame('returned', $plan['next_status']);
    }

    public function test_it_rejects_return_quantity_that_exceeds_remaining_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderReturnRefundCalculator::linePlan(
            line: [
                'Quantity' => 2,
                'Returned_Quantity' => 1,
                'Refunded_Amount' => '0.000',
                'Sold_Amount' => '20.000',
                'Status' => 'delivered',
            ],
            returnQuantity: 2,
            refundAmount: '0.000',
            restock: false,
        );
    }

    public function test_it_recalculates_vendor_commission_and_payout_after_refunds(): void
    {
        $plan = OrderReturnRefundCalculator::vendorPlan(
            vendorOrder: [
                'Sub_Total' => '100.000',
                'Commission_Type' => 'percent',
                'Commission_Value' => '10.000',
                'Commission_Amount' => '10.000',
                'Payout_Amount' => '90.000',
            ],
            refundedAmount: '30.000',
            returnedQuantity: 3,
        );

        $this->assertSame(3, $plan['returned_quantity']);
        $this->assertSame('30.000', $plan['refunded_amount']);
        $this->assertSame('70.000', $plan['net_sub_total']);
        $this->assertSame('7.000', $plan['adjusted_commission_amount']);
        $this->assertSame('63.000', $plan['net_payout_amount']);
        $this->assertSame('27.000', $plan['payout_adjustment_amount']);
    }
}
