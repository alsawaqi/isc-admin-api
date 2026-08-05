<?php

namespace App\Support;

use LogicException;

final class CommerceTestDataResetPlan
{
    public const CONFIRMATION = 'DELETE-TEST-COMMERCE-DATA';

    /**
     * Tables are deliberately ordered from children to parents.
     *
     * @return list<string>
     */
    public static function deletionTables(): array
    {
        $tables = [
            'Payment_Gateway_Events_T',
            'Payment_Gateway_Attempts_T',
            'Conx_Notifications_T',
            'Conx_Jobs_T',
            'Conx_Job_Batches_T',
            'Conx_Failed_Jobs_T',

            'Product_Question_Votes_T',
            'Product_Question_Answers_T',
            'Product_Questions_T',
            'Product_Review_Votes_T',
            'Product_Review_Replies_T',
            'Product_Reviews_T',
            'Customer_Back_In_Stock_Alerts_T',

            'Order_Process_Log_T',
            'Orders_Placed_Details_Adjustments_T',
            'Orders_Placed_Details_Cancelled_T',
            'Orders_Packaging_Details_T',
            'Orders_Financial_Transactions_T',
            'Orders_Customers_Grievances_T',
            'Orders_Customers_Feedback_T',
            'Defective_Products_Returns_T',
            'Sales_Returns_T',
            'Orders_Shipments_Details_T',
            'Sales_Transactions_Details_T',
            'Sales_Transaction_Header_T',
            'Customers_Loyalty_Transactions_T',

            'Orders_Placed_Details_T',
            'Orders_Placed_Vendors_T',
            'Orders_Placed_T',

            'Customers_Carts_T',
            'Customers_Wish_Lists_T',
            'Favorites_Master_T',

            'Products_Vendor_Requests_T',
            'Product_Specification_Product_Temp_T',
            'Products_Temporary_Bulk_Prices_T',
            'Products_Temporary_Images_T',
            'Products_Temporary_T',

            'Products_Bulk_Prices_T',
            'Products_Discounts_T',
            'Product_Specification_Product_T',
            'Product_Stock_Movements_T',
            'Procurement_Stock_Movements_T',
            'Procurement_Purchases_T',
            'Merchant_Product_Stock_T',
            'Product_Supplier_BarCode_T',
            'Products_Packs_Master_T',
            'Products_Images_T',
            'Products_Datasheet_T',

            'Products_Master_T',

            'Product_Specification_Value_T',
            'Product_Specification_Description_T',
            'Products_Manufacture_Master_T',
            'Products_Brands_Master_T',
            'Products_Types_Master_T',
            'Products_Sub_Sub_Department_T',
            'Products_Sub_Department_T',
            'Products_Departments_T',
            'Product_Hierarchy_Import_Jobs_T',
        ];

        self::assertSafeIdentifiers($tables);

        if (count($tables) !== count(array_unique($tables))) {
            throw new LogicException('The commerce reset plan contains duplicate tables.');
        }

        return $tables;
    }

    /** @return list<string> */
    public static function preservedTables(): array
    {
        $tables = [
            'Secx_User_Master_T',
            'Secx_Admin_User_Master_T',
            'Customers_Master_T',
            'Customers_Contact_T',
            'Customers_Types_Master_T',
            'Customers_Loyalty_T',
            'Credit_Customers_T',
            'personal_access_tokens',
            'Conx_Sessions_T',
            'Conx_Notification_Devices_T',
            'Company_Types_Master_T',
            'Company_Master_T',
            'Company_Contacts_T',
            'Merchants_Master_T',
            'Vendors_Master_T',
            'Secx_Vendors_Users_Master_T',
            'Vendor_Documents_T',
            'Security_Roles_T',
            'Security_Permissions_T',
            'Security_Role_Has_Permissions_T',
            'Security_Model_Has_Roles_T',
            'Security_Model_Has_Permissions_T',
            'Vat_Master_T',
            'System_Parameter_Loyalty_Points_T',
            'System_Parameter_Loyalty_Points_History_T',
            'System_Parameter_UI_Sliders_T',
            'Procurement_Suppliers_T',
            'Orders_Feedbacks_Master_T',
            'Shippers_Master_T',
            'Shipper_Contacts_T',
            'Shipper_Destinations_T',
            'Shipper_Shipping_Rates_T',
            'Shipper_Volume_Rates_T',
            'Shipper_Weight_Rates_T',
            'Shipper_Heavy_Rates_T',
            'Shipper_Box_Sizes_T',
            'Shipper_Box_Rates_T',
            'Shipper_Volumetric_Rules_T',
            'Heavy_Vehicles_T',
            'Geox_Country_Master_T',
            'Geox_Region_Master_T',
            'Geox_State_Master_T',
            'Geox_Zone_Master_T',
            'Geox_Jurisdiction_Master_T',
            'Geox_District_Master_T',
            'Geox_City_Master_T',
            'Geox_Location_Master_T',
            'Contact_Departments_T',
            'Titles_Master_T',
            'Designations_Master_T',
        ];

        self::assertSafeIdentifiers($tables);

        return $tables;
    }

    /** @param list<string> $identifiers */
    private static function assertSafeIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (! preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $identifier)) {
                throw new LogicException("Unsafe SQL identifier in commerce reset plan: {$identifier}");
            }
        }
    }
}
