<?php

namespace Tests\Unit\Vendors;

use App\Support\Vendors\VendorOnboardingChecklist;
use PHPUnit\Framework\TestCase;

class VendorOnboardingChecklistTest extends TestCase
{
    public function test_it_calculates_vendor_profile_completeness_from_business_bank_and_document_requirements(): void
    {
        $result = VendorOnboardingChecklist::evaluate(
            vendor: [
                'Vendor_Name' => 'Gulf Tools LLC',
                'Trade_Name' => 'Gulf Tools',
                'CR_Number' => 'CR-100',
                'VAT_Number' => 'VAT-100',
                'Email_1' => 'ops@gulftools.test',
                'Phone_No' => '+96890000000',
                'Address_Line1' => 'Industrial Area',
                'Country_Id' => 1,
                'Region_Id' => 2,
                'City_Id' => 3,
                'Bank_Name' => 'Bank Muscat',
                'Bank_Account_Name' => 'Gulf Tools LLC',
                'Bank_IBAN' => 'OM000000000000000000000',
                'Payout_Method' => 'bank_transfer',
            ],
            documents: [
                ['Document_Type' => 'commercial_registration', 'Status' => 'approved'],
                ['Document_Type' => 'vat_certificate', 'Status' => 'pending'],
            ],
        );

        $this->assertSame(4, $result['completed_count']);
        $this->assertSame(5, $result['total_count']);
        $this->assertSame(80, $result['completeness_percent']);
        $this->assertSame(['documents'], $result['missing_required']);
        $this->assertSame('incomplete', $result['readiness']);
    }

    public function test_it_marks_vendor_ready_when_required_profile_and_documents_are_complete(): void
    {
        $result = VendorOnboardingChecklist::evaluate(
            vendor: [
                'Vendor_Name' => 'Complete Vendor',
                'Trade_Name' => 'Complete Trade',
                'CR_Number' => 'CR-200',
                'VAT_Number' => 'VAT-200',
                'Email_1' => 'vendor@example.test',
                'Phone_No' => '+96890000001',
                'Address_Line1' => 'Main Street',
                'Country_Id' => 1,
                'Region_Id' => 1,
                'City_Id' => 1,
                'Bank_Name' => 'NBO',
                'Bank_Account_Name' => 'Complete Vendor',
                'Bank_Account_Number' => '123456789',
                'Payout_Method' => 'bank_transfer',
            ],
            documents: [
                ['Document_Type' => 'commercial_registration', 'Status' => 'approved'],
                ['Document_Type' => 'vat_certificate', 'Status' => 'approved'],
                ['Document_Type' => 'bank_letter', 'Status' => 'approved'],
            ],
        );

        $this->assertSame(100, $result['completeness_percent']);
        $this->assertSame([], $result['missing_required']);
        $this->assertSame('ready_for_review', $result['readiness']);
    }
}
