<?php

namespace Tests\Unit\Vendors;

use App\Support\Vendors\VendorApprovalSla;
use App\Support\Vendors\VendorPayoutRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VendorApprovalAndPayoutTest extends TestCase
{
    public function test_product_approval_sla_marks_pending_submission_as_overdue(): void
    {
        $result = VendorApprovalSla::forProduct(
            product: [
                'Submission_Status' => 'pending',
                'Submitted_At' => '2026-05-01 09:00:00',
            ],
            now: CarbonImmutable::parse('2026-05-04 10:00:00'),
            slaHours: 48,
        );

        $this->assertSame('overdue', $result['sla_status']);
        $this->assertSame(-25, $result['hours_remaining']);
        $this->assertSame('2026-05-03 09:00:00', $result['sla_due_at']);
    }

    public function test_product_approval_sla_marks_reviewed_submission_as_completed(): void
    {
        $result = VendorApprovalSla::forProduct(
            product: [
                'Submission_Status' => 'approved',
                'Submitted_At' => '2026-05-01 09:00:00',
                'Reviewed_At' => '2026-05-02 10:00:00',
            ],
            now: CarbonImmutable::parse('2026-05-04 10:00:00'),
            slaHours: 48,
        );

        $this->assertSame('completed', $result['sla_status']);
        $this->assertSame(25, $result['hours_to_review']);
    }

    public function test_payout_rules_use_net_subtotal_and_adjusted_commission_for_paid_amount(): void
    {
        $result = VendorPayoutRules::paidPayload([
            'Vendor_Id' => 7,
            'Sub_Total' => '100.000',
            'Commission_Amount' => '10.000',
            'Net_Sub_Total' => '70.000',
            'Adjusted_Commission_Amount' => '7.000',
        ], 'BANK-REF-1', '2026-05-04 12:00:00');

        $this->assertSame('63.000', $result['Payout_Amount']);
        $this->assertSame('paid', $result['Payout_Status']);
        $this->assertSame('BANK-REF-1', $result['Payout_Reference']);
    }

    public function test_payout_rules_reject_negative_payouts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VendorPayoutRules::paidPayload([
            'Net_Sub_Total' => '5.000',
            'Adjusted_Commission_Amount' => '7.000',
        ]);
    }
}
