<?php

namespace Tests\Unit\Payments;

use App\Http\Controllers\Admin\VendorOrdersController;
use App\Http\Controllers\OrdersPlacedController;
use App\Models\OrdersPlaced;
use App\Models\OrdersPlacedVendors;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AmwalReconciliationGuardsTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->createSchema();
        $this->seedDuplicateCapture();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        config()->set('database.default', $this->originalConnection);
        DB::setDefaultConnection($this->originalConnection);

        parent::tearDown();
    }

    public function test_fulfillment_rejects_an_order_with_any_review_attempt(): void
    {
        $order = OrdersPlaced::query()->findOrFail(10);
        $method = new ReflectionMethod(OrdersPlacedController::class, 'ensureCardPaymentSettled');
        $method->setAccessible(true);

        try {
            $method->invoke(new OrdersPlacedController, $order);
            $this->fail('A duplicate capture under review must block fulfillment.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('reconciliation', $exception->getMessage());
        }
    }

    public function test_vendor_commission_and_payout_guard_rejects_any_review_attempt(): void
    {
        $vendorOrder = OrdersPlacedVendors::query()->findOrFail(60);
        $method = new ReflectionMethod(VendorOrdersController::class, 'unpaidCardOrderReason');
        $method->setAccessible(true);

        $reason = $method->invoke(new VendorOrdersController, $vendorOrder);

        $this->assertIsString($reason);
        $this->assertStringContainsString('verified payment', $reason);
    }

    private function seedDuplicateCapture(): void
    {
        DB::table('Orders_Placed_T')->insert([
            'id' => 10,
            'Payment_Method' => 'card',
            'Payment_Status' => 'paid',
            'Status' => 'pending',
        ]);
        DB::table('Sales_Transaction_Header_T')->insert([
            'id' => 15,
            'Orders_Placed_Id' => 10,
        ]);
        DB::table('Sales_Transactions_Details_T')->insert([
            'id' => 20,
            'Sales_Transaction_Header_Id' => 15,
            'Payment_Method' => 'card',
            'Payment_Gateway' => 'amwal_smartbox',
            'Payment_Status' => 'paid',
        ]);
        DB::table('Payment_Gateway_Attempts_T')->insert([
            [
                'Orders_Placed_Id' => 10,
                'Gateway' => 'amwal_smartbox',
                'Status' => 'paid',
            ],
            [
                'Orders_Placed_Id' => 10,
                'Gateway' => 'amwal_smartbox',
                'Status' => 'paid_requires_review',
            ],
        ]);
        DB::table('Orders_Placed_Vendors_T')->insert([
            'id' => 60,
            'Orders_Placed_Id' => 10,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'Orders_Placed_Vendors_T',
            'Payment_Gateway_Attempts_T',
            'Sales_Transactions_Details_T',
            'Sales_Transaction_Header_T',
            'Orders_Placed_T',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->string('Payment_Method')->nullable();
            $table->string('Payment_Status')->nullable();
            $table->string('Status')->nullable();
        });
        Schema::create('Sales_Transaction_Header_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
        });
        Schema::create('Sales_Transactions_Details_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Sales_Transaction_Header_Id');
            $table->string('Payment_Method')->nullable();
            $table->string('Payment_Gateway')->nullable();
            $table->string('Payment_Status')->nullable();
        });
        Schema::create('Payment_Gateway_Attempts_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->string('Gateway');
            $table->string('Status');
        });
        Schema::create('Orders_Placed_Vendors_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
        });
    }
}
