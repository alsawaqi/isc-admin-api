<?php

namespace Tests\Unit;

use App\Support\CommerceTestDataResetPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommerceTestDataResetPlanTest extends TestCase
{
    public function test_plan_has_unique_safe_table_names(): void
    {
        $tables = CommerceTestDataResetPlan::deletionTables();

        self::assertSame($tables, array_values(array_unique($tables)));
        foreach ($tables as $table) {
            self::assertMatchesRegularExpression('/\A[A-Za-z][A-Za-z0-9_]*\z/', $table);
        }
    }

    public function test_preserved_tables_never_appear_in_deletion_plan(): void
    {
        self::assertSame([], array_values(array_intersect(
            CommerceTestDataResetPlan::deletionTables(),
            CommerceTestDataResetPlan::preservedTables(),
        )));
    }

    public function test_identity_and_configuration_tables_are_explicitly_preserved(): void
    {
        $tables = CommerceTestDataResetPlan::preservedTables();

        foreach ([
            'Secx_User_Master_T',
            'Secx_Admin_User_Master_T',
            'Customers_Master_T',
            'Customers_Contact_T',
            'Customers_Loyalty_T',
            'Credit_Customers_T',
            'Vendors_Master_T',
            'Secx_Vendors_Users_Master_T',
            'Vendor_Documents_T',
            'Security_Roles_T',
            'Vat_Master_T',
            'System_Parameter_Loyalty_Points_T',
            'System_Parameter_UI_Sliders_T',
            'Procurement_Suppliers_T',
            'Shippers_Master_T',
            'Geox_Country_Master_T',
        ] as $required) {
            self::assertContains($required, $tables);
        }
    }

    #[DataProvider('childParentPairs')]
    public function test_children_are_deleted_before_parents(string $child, string $parent): void
    {
        $tables = CommerceTestDataResetPlan::deletionTables();

        self::assertLessThan(
            array_search($parent, $tables, true),
            array_search($child, $tables, true),
            "{$child} must be deleted before {$parent}",
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function childParentPairs(): iterable
    {
        yield 'gateway event before attempt' => ['Payment_Gateway_Events_T', 'Payment_Gateway_Attempts_T'];
        yield 'order log before cancelled detail' => ['Order_Process_Log_T', 'Orders_Placed_Details_Cancelled_T'];
        yield 'cancelled detail before detail' => ['Orders_Placed_Details_Cancelled_T', 'Orders_Placed_Details_T'];
        yield 'detail before vendor order' => ['Orders_Placed_Details_T', 'Orders_Placed_Vendors_T'];
        yield 'detail before order' => ['Orders_Placed_Details_T', 'Orders_Placed_T'];
        yield 'temp image before temp product' => ['Products_Temporary_Images_T', 'Products_Temporary_T'];
        yield 'stock movement before purchase' => ['Procurement_Stock_Movements_T', 'Procurement_Purchases_T'];
        yield 'product image before product' => ['Products_Images_T', 'Products_Master_T'];
        yield 'product before department' => ['Products_Master_T', 'Products_Departments_T'];
        yield 'sub-sub before sub' => ['Products_Sub_Sub_Department_T', 'Products_Sub_Department_T'];
        yield 'sub before department' => ['Products_Sub_Department_T', 'Products_Departments_T'];
    }

    public function test_logical_non_fk_tables_are_explicitly_included(): void
    {
        $tables = CommerceTestDataResetPlan::deletionTables();

        foreach ([
            'Payment_Gateway_Attempts_T',
            'Products_Vendor_Requests_T',
            'Products_Discounts_T',
            'Products_Bulk_Prices_T',
            'Products_Temporary_Bulk_Prices_T',
            'Product_Reviews_T',
            'Product_Questions_T',
            'Customer_Back_In_Stock_Alerts_T',
        ] as $required) {
            self::assertContains($required, $tables);
        }
    }

    public function test_confirmation_token_is_intentionally_specific(): void
    {
        self::assertSame('DELETE-TEST-COMMERCE-DATA', CommerceTestDataResetPlan::CONFIRMATION);
    }
}
