<?php

namespace Tests\Unit\Reports;

use App\Support\Reports\OperationsReportBuilder;
use PHPUnit\Framework\TestCase;

class OperationsReportBuilderTest extends TestCase
{
    public function test_it_summarizes_net_sales_refunds_returns_and_payouts(): void
    {
        $summary = OperationsReportBuilder::summaryFromRows([
            'net_sales' => [
                ['sold_amount' => '120.000', 'refunded_amount' => '20.000', 'returned_quantity' => 2, 'net_amount' => '100.000'],
                ['sold_amount' => '80.000', 'refunded_amount' => '0.000', 'returned_quantity' => 0, 'net_amount' => '80.000'],
            ],
            'payouts' => [
                ['payout_amount' => '50.000', 'payout_status' => 'paid'],
                ['payout_amount' => '25.000', 'payout_status' => 'unpaid'],
            ],
        ]);

        $this->assertSame('200.000', $summary['sold_amount']);
        $this->assertSame('20.000', $summary['refunded_amount']);
        $this->assertSame(2, $summary['returned_quantity']);
        $this->assertSame('180.000', $summary['net_amount']);
        $this->assertSame('50.000', $summary['paid_payout_amount']);
        $this->assertSame('25.000', $summary['pending_payout_amount']);
    }

    public function test_it_exports_rows_to_csv_with_stable_headers(): void
    {
        $csv = OperationsReportBuilder::toCsv([
            ['vendor' => 'Gulf Tools', 'net_amount' => '100.000'],
            ['vendor' => 'ISC', 'net_amount' => '75.000'],
        ]);

        $this->assertStringStartsWith("vendor,net_amount\n", $csv);
        $this->assertStringContainsString("Gulf Tools,100.000\n", $csv);
        $this->assertStringContainsString("ISC,75.000\n", $csv);
    }
}
