<?php

namespace Tests\Feature\Orders;

use App\Models\OrdersPlaced;
use App\Models\ProductMaster;
use App\Services\Orders\UnpaidAmwalOrderCancellationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\FeatureTestCase;

class UnpaidAmwalOrderCancellationServiceTest extends FeatureTestCase
{
    public function test_full_unpaid_cancellation_releases_stock_and_loyalty_once(): void
    {
        $fixture = $this->fixture();
        $service = app(UnpaidAmwalOrderCancellationService::class);

        $result = $service->cancel(
            orderId: $fixture['order']->id,
            actorId: $fixture['admin_id'],
            actorName: 'Amwal cancellation test',
            actorRole: 'accounting',
            signature: ['url' => 'orders/test/signature.png', 'mime' => 'image/png'],
            note: 'Customer cancelled before payment.',
        );

        $this->assertFalse($result['idempotent']);
        $this->assertSame(1, $result['released_lines']);
        $this->assertSame(100, $result['released_loyalty_points']);
        $this->assertTrue($result['cart_restoration']['performed']);
        $this->assertSame('admin', $result['cart_restoration']['source']);
        $this->assertSame(2, $result['cart_restoration']['restored_quantity']);
        $this->assertSame(5, (int) DB::table('Products_Master_T')->where('id', $fixture['product_id'])->value('Product_Stock'));
        $this->assertSame(3, (int) DB::table('Customers_Carts_T')->where('id', $fixture['cart_id'])->value('Quantity'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_T')->where('id', $fixture['order']->id)->value('Status'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_T')->where('id', $fixture['order']->id)->value('Payment_Status'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_Details_T')->where('id', $fixture['line_id'])->value('Status'));
        $this->assertSame('cancelled', DB::table('Sales_Transactions_Details_T')->where('id', $fixture['payment_id'])->value('Payment_Status'));
        $this->assertSame('cancelled', DB::table('Payment_Gateway_Attempts_T')->where('id', $fixture['attempt_id'])->value('Status'));
        $this->assertSame(0, (int) DB::table('Customers_Loyalty_T')->where('id', $fixture['loyalty_id'])->value('Points_Redeemed'));
        $this->assertSame(-100, (int) DB::table('Customers_Loyalty_Transactions_T')->where('Loyalty_Transaction_Code', 'LOYREV-'.$fixture['order']->id)->value('Points_Redeemed'));
        $this->assertSame(1, DB::table('Product_Stock_Movements_T')
            ->where('Products_Id', $fixture['product_id'])
            ->where('Movement_Type', 'order_cancellation_release')
            ->count());

        $again = $service->cancel(
            orderId: $fixture['order']->id,
            actorId: $fixture['admin_id'],
            actorName: 'Amwal cancellation test',
            actorRole: 'accounting',
            signature: ['url' => 'orders/test/signature.png', 'mime' => 'image/png'],
            note: 'Repeated request.',
        );

        $this->assertTrue($again['idempotent']);
        $this->assertTrue($again['cart_restoration']['performed']);
        $this->assertSame(2, $again['cart_restoration']['restored_quantity']);
        $this->assertSame(5, (int) DB::table('Products_Master_T')->where('id', $fixture['product_id'])->value('Product_Stock'));
        $this->assertSame(3, (int) DB::table('Customers_Carts_T')->where('id', $fixture['cart_id'])->value('Quantity'));
        $this->assertSame(1, DB::table('Customers_Loyalty_Transactions_T')->where('Loyalty_Transaction_Code', 'LOYREV-'.$fixture['order']->id)->count());

        $paymentMetadata = json_decode((string) DB::table('Sales_Transactions_Details_T')
            ->where('id', $fixture['payment_id'])
            ->value('Payment_Metadata'), true);
        $attemptMetadata = json_decode((string) DB::table('Payment_Gateway_Attempts_T')
            ->where('id', $fixture['attempt_id'])
            ->value('Metadata'), true);
        $this->assertSame(2, $paymentMetadata['cart_restoration']['restored_quantity']);
        $this->assertSame(2, $attemptMetadata['cart_restoration']['restored_quantity']);
    }

    public function test_captured_payment_aborts_without_releasing_reservations(): void
    {
        $fixture = $this->fixture();
        DB::table('Orders_Placed_T')->where('id', $fixture['order']->id)->update(['Payment_Status' => 'paid']);
        DB::table('Sales_Transactions_Details_T')->where('id', $fixture['payment_id'])->update(['Payment_Status' => 'paid']);
        DB::table('Payment_Gateway_Attempts_T')->where('id', $fixture['attempt_id'])->update([
            'Status' => 'paid',
            'Gateway_Transaction_Id' => 'CAPTURED-'.uniqid(),
        ]);

        try {
            app(UnpaidAmwalOrderCancellationService::class)->cancel(
                orderId: $fixture['order']->id,
                actorId: $fixture['admin_id'],
                actorName: 'Amwal cancellation test',
                actorRole: 'accounting',
                signature: ['url' => 'orders/test/signature.png', 'mime' => 'image/png'],
                note: 'Must be rejected.',
            );
            $this->fail('A captured AmwalPay order must not be cancelled locally.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(3, (int) DB::table('Products_Master_T')->where('id', $fixture['product_id'])->value('Product_Stock'));
        $this->assertSame(100, (int) DB::table('Customers_Loyalty_T')->where('id', $fixture['loyalty_id'])->value('Points_Redeemed'));
        $this->assertSame('pending', DB::table('Orders_Placed_Details_T')->where('id', $fixture['line_id'])->value('Status'));
    }

    public function test_partial_amwal_cancellation_is_rejected_before_any_release(): void
    {
        $fixture = $this->fixture();

        $response = $this->post('/api/orders-placed/'.$fixture['order']->id.'/cancel', [
            'selected_lines' => [$fixture['line_id']],
            'signature' => UploadedFile::fake()->createWithContent(
                'signature.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nMsAAAAASUVORK5CYII='),
            ),
            'note' => 'Partial cancellation is not safe for a signed full amount.',
        ]);

        $response->assertStatus(409);
        $this->assertSame(3, (int) DB::table('Products_Master_T')->where('id', $fixture['product_id'])->value('Product_Stock'));
        $this->assertSame('pending', DB::table('Orders_Placed_T')->where('id', $fixture['order']->id)->value('Status'));
        $this->assertSame('pending', DB::table('Payment_Gateway_Attempts_T')->where('id', $fixture['attempt_id'])->value('Status'));
    }

    /**
     * @return array{order: OrdersPlaced, admin_id: int, product_id: int, line_id: int, payment_id: int, attempt_id: int, loyalty_id: int, cart_id: int}
     */
    private function fixture(): array
    {
        $admin = $this->actingAsAdmin();
        $referenceProduct = DB::table('Products_Master_T')
            ->whereNotNull('Product_Department_Id')
            ->whereNotNull('Product_Sub_Department_Id')
            ->whereNotNull('Product_Sub_Sub_Department_Id')
            ->first();
        $customerId = DB::table('Customers_Master_T')->value('id');

        if (!$referenceProduct || !$customerId) {
            $this->markTestSkipped('Reference product categories and a customer are required.');
        }

        $suffix = strtoupper(substr(uniqid(), -8));
        $product = ProductMaster::create([
            'Product_Code' => 'AMWC'.$suffix,
            'Product_Department_Id' => $referenceProduct->Product_Department_Id,
            'Product_Sub_Department_Id' => $referenceProduct->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $referenceProduct->Product_Sub_Sub_Department_Id,
            'Product_Name' => 'Amwal cancellation test '.$suffix,
            'Product_Name_Ar' => 'اختبار إلغاء أموال',
            'Product_Description' => 'Reserved stock cancellation test.',
            'Product_Price' => 10,
            'Product_Stock' => 3,
            'Status' => 'available',
            'Created_By' => $admin->id,
        ]);
        DB::table('Customers_Carts_T')
            ->where('Customers_Id', $customerId)
            ->where('Products_Id', $product->id)
            ->delete();
        $cartId = (int) DB::table('Customers_Carts_T')->insertGetId([
            'Customers_Id' => $customerId,
            'Products_Id' => $product->id,
            'Quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = OrdersPlaced::create([
            'Order_Code' => 'AMWO'.$suffix,
            'Transaction_Number' => 'AMWT'.$suffix,
            'Customers_Id' => $customerId,
            'Status' => 'pending',
            'Payment_Method' => 'card',
            'Payment_Status' => 'pending',
            'Sub_Total_Price' => 20,
            'Total_Price' => 19.900,
            'Loyalty_Points_Redeemed' => 100,
            'Loyalty_Discount_Amount' => 0.100,
        ]);
        $lineId = (int) DB::table('Orders_Placed_Details_T')->insertGetId([
            'Order_Placed_Code' => 'AMWL'.$suffix,
            'Orders_Placed_Id' => $order->id,
            'Products_Id' => $product->id,
            'Quantity' => 2,
            'Price' => 10,
            'Subtotal' => 20,
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $headerId = (int) DB::table('Sales_Transaction_Header_T')->insertGetId([
            'Sales_Transaction_Header_code' => 'AMWH'.$suffix,
            'Merchant_Id' => 'AMW'.$suffix,
            'Bill_No' => 'B'.$suffix,
            'Orders_Placed_Id' => $order->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = (int) DB::table('Sales_Transactions_Details_T')->insertGetId([
            'Sales_Transactions_Details_code' => 'AMWD'.$suffix,
            'Sales_Transaction_Header_Id' => $headerId,
            'Transaction_No' => 'T'.$suffix,
            'Merchant_Id' => 'AMW'.$suffix,
            'Bill_No' => 'B'.$suffix,
            'Payment_Method' => 'card',
            'Payment_Status' => 'pending',
            'Payment_Amount' => 19.900,
            'Payment_Currency' => 'OMR',
            'Payment_Gateway' => 'amwal_smartbox',
            'Payment_Intent_Id' => 'AMWR'.$suffix,
            'Payment_Metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attemptId = (int) DB::table('Payment_Gateway_Attempts_T')->insertGetId([
            'Orders_Placed_Id' => $order->id,
            'Sales_Transactions_Details_Id' => $paymentId,
            'Gateway' => 'amwal_smartbox',
            'Merchant_Reference' => 'AMWR'.$suffix,
            'Amount' => 19.900,
            'Currency' => 'OMR',
            'Currency_Id' => '512',
            'Status' => 'pending',
            'Initiated_At' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loyaltyId = DB::table('Customers_Loyalty_T')->where('Customer_Id', $customerId)->value('id');
        if ($loyaltyId) {
            DB::table('Customers_Loyalty_T')->where('id', $loyaltyId)->update([
                'Points_Redeemed' => 100,
                'updated_at' => now(),
            ]);
        } else {
            $loyaltyId = DB::table('Customers_Loyalty_T')->insertGetId([
                'Customers_Loyalty_Code' => 'AMWLOY'.$suffix,
                'Customer_Id' => $customerId,
                'Points_Earned' => 1000,
                'Points_Redeemed' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('Customers_Loyalty_Transactions_T')->insert([
            'Loyalty_Transaction_Code' => 'AMWRED'.$suffix,
            'Customer_Id' => $customerId,
            'Orders_Placed_Id' => $order->id,
            'Points_Earned' => 0,
            'Points_Redeemed' => 100,
            'Redeemed_Amount' => 0.100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'order' => $order,
            'admin_id' => (int) $admin->id,
            'product_id' => (int) $product->id,
            'line_id' => $lineId,
            'payment_id' => $paymentId,
            'attempt_id' => $attemptId,
            'loyalty_id' => (int) $loyaltyId,
            'cart_id' => $cartId,
        ];
    }
}
