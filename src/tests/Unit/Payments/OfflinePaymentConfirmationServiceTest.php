<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\OfflinePaymentConfirmationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class OfflinePaymentConfirmationServiceTest extends TestCase
{
    private string $originalConnection;

    private OfflinePaymentConfirmationService $service;

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
        $this->seedTransferOrder();
        $this->service = new OfflinePaymentConfirmationService;
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        config()->set('database.default', $this->originalConnection);
        DB::setDefaultConnection($this->originalConnection);

        parent::tearDown();
    }

    public function test_verified_transfer_awards_points_once_and_is_idempotent(): void
    {
        $first = $this->confirm(transferReference: 'BANK-VERIFIED-100');

        $this->assertFalse($first['idempotent']);
        $this->assertSame(1500, $first['points_awarded']);
        $this->assertSame('paid', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('paid', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame('BANK-VERIFIED-100', DB::table('Sales_Transactions_Details_T')->value('Transfer_Reference'));
        $this->assertNotNull(DB::table('Sales_Transactions_Details_T')->value('Transfer_Received_At'));
        $this->assertSame(1500, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
        $this->assertSame(1, DB::table('Customers_Loyalty_Transactions_T')->count());
        $this->assertSame('LOY_EARN_10', DB::table('Customers_Loyalty_Transactions_T')->value('Loyalty_Transaction_Code'));
        $this->assertSame(1, DB::table('Order_Process_Log_T')->count());

        $second = $this->confirm(transferReference: null);

        $this->assertTrue($second['idempotent']);
        $this->assertTrue($second['points_previously_awarded']);
        $this->assertSame(0, $second['points_awarded']);
        $this->assertSame(1500, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
        $this->assertSame(1, DB::table('Customers_Loyalty_Transactions_T')->count());
        $this->assertSame(1, DB::table('Order_Process_Log_T')->count());
    }

    public function test_cod_awards_only_after_handover_and_records_collection(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'Payment_Method' => 'cod',
            'Status' => 'delivered',
        ]);
        DB::table('Sales_Transactions_Details_T')->where('id', 30)->update([
            'Payment_Method' => 'cod',
            'Transfer_Reference' => null,
        ]);

        $result = $this->confirm(transferReference: null);

        $this->assertSame(1500, $result['points_awarded']);
        $this->assertSame('paid', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('paid', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame(1, (int) DB::table('Sales_Transactions_Details_T')->value('COD_Collected'));
        $this->assertNotNull(DB::table('Sales_Transactions_Details_T')->value('COD_Collected_At'));
        $this->assertSame('Verified against settlement evidence.', DB::table('Sales_Transactions_Details_T')->value('COD_Note'));
    }

    public function test_cod_cannot_be_confirmed_before_handover(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Payment_Method' => 'cod']);
        DB::table('Sales_Transactions_Details_T')->where('id', 30)->update(['Payment_Method' => 'cod']);

        try {
            $this->confirm(transferReference: null);
            $this->fail('Pending COD should not be treated as collected.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('after the order is handed over or delivered', $exception->getMessage());
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame(0, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
        $this->assertSame(0, DB::table('Customers_Loyalty_Transactions_T')->count());
    }

    public function test_cod_cannot_be_confirmed_merely_because_it_was_shipped(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'Payment_Method' => 'cod',
            'Status' => 'shipped',
        ]);
        DB::table('Sales_Transactions_Details_T')->where('id', 30)->update(['Payment_Method' => 'cod']);

        $this->expectException(ConflictHttpException::class);

        try {
            $this->confirm(transferReference: null);
        } finally {
            $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
            $this->assertSame(0, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
            $this->assertSame(0, DB::table('Customers_Loyalty_Transactions_T')->count());
        }
    }

    public function test_amount_mismatch_rolls_back_payment_and_loyalty(): void
    {
        DB::table('Sales_Transactions_Details_T')->where('id', 30)->update(['Payment_Amount' => 9.999]);

        $this->expectException(ConflictHttpException::class);

        try {
            $this->confirm(transferReference: 'BANK-VERIFIED-100');
        } finally {
            $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
            $this->assertSame('pending', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
            $this->assertSame(0, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
            $this->assertSame(0, DB::table('Customers_Loyalty_Transactions_T')->count());
        }
    }

    public function test_partially_refunded_transfer_cannot_earn_on_the_original_total(): void
    {
        DB::table('Orders_Placed_Details_T')->where('id', 11)->update([
            'Refund_State' => 'partially_refunded',
            'Refunded_Amount' => 1.000,
        ]);

        try {
            $this->confirm(transferReference: 'BANK-VERIFIED-100');
            $this->fail('An unreconciled partial refund must prevent payment settlement.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('cancelled or refunded lines', $exception->getMessage());
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('pending', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame(0, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
        $this->assertSame(0, DB::table('Customers_Loyalty_Transactions_T')->count());
    }

    public function test_failed_transfer_cannot_be_changed_to_paid_or_earn_points(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Payment_Status' => 'failed']);
        DB::table('Sales_Transactions_Details_T')->where('id', 30)->update(['Payment_Status' => 'failed']);

        try {
            $this->confirm(transferReference: 'BANK-VERIFIED-100');
            $this->fail('A failed payment must not be promoted to paid.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('current status', $exception->getMessage());
        }

        $this->assertSame('failed', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('failed', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame(0, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
        $this->assertSame(0, DB::table('Customers_Loyalty_Transactions_T')->count());
    }

    public function test_legacy_order_with_existing_earn_is_not_awarded_twice(): void
    {
        DB::table('Customers_Loyalty_T')->where('Customer_Id', 77)->update(['Points_Earned' => 1500]);
        DB::table('Customers_Loyalty_Transactions_T')->insert([
            'Loyalty_Transaction_Code' => 'LEGACY-EARN-10',
            'Customer_Id' => 77,
            'Orders_Placed_Id' => 10,
            'Points_Earned' => 1500,
            'Points_Redeemed' => 0,
            'Redeemed_Amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->confirm(transferReference: 'BANK-VERIFIED-100');

        $this->assertTrue($result['points_previously_awarded']);
        $this->assertSame(0, $result['points_awarded']);
        $this->assertSame(1500, (int) DB::table('Customers_Loyalty_T')->value('Points_Earned'));
        $this->assertSame(1, DB::table('Customers_Loyalty_Transactions_T')->count());
    }

    private function confirm(?string $transferReference): array
    {
        return $this->service->confirm(
            orderId: 10,
            actorId: 5,
            actorName: 'Finance Tester',
            actorRole: 'finance',
            note: 'Verified against settlement evidence.',
            transferReference: $transferReference,
            signature: [
                'url' => 'https://example.test/signature.png',
                'mime' => 'image/png',
            ],
        );
    }

    private function seedTransferOrder(): void
    {
        DB::table('Customers_Master_T')->insert(['id' => 77]);
        DB::table('Orders_Placed_T')->insert([
            'id' => 10,
            'Customers_Id' => 77,
            'Total_Price' => 10.000,
            'Payment_Method' => 'transfer',
            'Payment_Status' => 'pending',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Orders_Placed_Details_T')->insert([
            'id' => 11,
            'Orders_Placed_Id' => 10,
            'Status' => 'pending',
        ]);
        DB::table('Sales_Transaction_Header_T')->insert([
            'id' => 20,
            'Orders_Placed_Id' => 10,
        ]);
        DB::table('Sales_Transactions_Details_T')->insert([
            'id' => 30,
            'Sales_Transaction_Header_Id' => 20,
            'Payment_Method' => 'transfer',
            'Payment_Status' => 'pending',
            'Payment_Amount' => 10.000,
            'Payment_Currency' => 'OMR',
            'Payment_Gateway' => null,
            'Transfer_Reference' => 'CUSTOMER-REFERENCE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Customers_Loyalty_T')->insert([
            'id' => 40,
            'Customers_Loyalty_Code' => 'LOY-BALANCE-77',
            'Customer_Id' => 77,
            'Points_Earned' => 0,
            'Points_Redeemed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('System_Parameter_Loyalty_Points_T')->insert([
            'id' => 50,
            'Earn_Amount' => 1,
            'Earn_Points' => 150,
            'Point' => 150,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('Customers_Master_T', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Customers_Id');
            $table->decimal('Total_Price', 18, 3);
            $table->string('Payment_Method');
            $table->string('Payment_Status');
            $table->string('Status');
            $table->timestamps();
        });
        Schema::create('Orders_Placed_Details_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->string('Status');
            $table->unsignedInteger('Returned_Quantity')->default(0);
            $table->decimal('Refunded_Amount', 18, 3)->default(0);
            $table->string('Return_State')->nullable();
            $table->string('Refund_State')->nullable();
        });
        Schema::create('Sales_Transaction_Header_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
        });
        Schema::create('Sales_Transactions_Details_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Sales_Transaction_Header_Id');
            $table->string('Payment_Method');
            $table->string('Payment_Status');
            $table->decimal('Payment_Amount', 18, 3);
            $table->string('Payment_Currency', 3);
            $table->string('Payment_Gateway')->nullable();
            $table->boolean('COD_Collected')->nullable();
            $table->dateTime('COD_Collected_At')->nullable();
            $table->string('COD_Note', 300)->nullable();
            $table->string('Transfer_Reference', 120)->nullable();
            $table->dateTime('Transfer_Received_At')->nullable();
            $table->timestamps();
        });
        Schema::create('Customers_Loyalty_T', function (Blueprint $table) {
            $table->id();
            $table->string('Customers_Loyalty_Code', 30)->unique()->nullable();
            $table->unsignedBigInteger('Customer_Id');
            $table->integer('Points_Earned')->default(0);
            $table->integer('Points_Redeemed')->default(0);
            $table->timestamps();
        });
        Schema::create('Customers_Loyalty_Transactions_T', function (Blueprint $table) {
            $table->id();
            $table->string('Loyalty_Transaction_Code', 30)->unique();
            $table->unsignedBigInteger('Customer_Id');
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->integer('Points_Earned')->default(0);
            $table->integer('Points_Redeemed')->default(0);
            $table->decimal('Redeemed_Amount', 18, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('System_Parameter_Loyalty_Points_T', function (Blueprint $table) {
            $table->id();
            $table->decimal('Point', 18, 3)->nullable();
            $table->decimal('Earn_Amount', 18, 3)->nullable();
            $table->decimal('Earn_Points', 18, 3)->nullable();
        });
        Schema::create('Order_Process_Log_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->string('Step_Code');
            $table->string('Status');
            $table->boolean('Is_External');
            $table->unsignedBigInteger('Actor_User_Id')->nullable();
            $table->string('Actor_Name')->nullable();
            $table->string('Actor_Role')->nullable();
            $table->dateTime('Signed_At')->nullable();
            $table->string('Signature_Url')->nullable();
            $table->string('Signature_Mime')->nullable();
            $table->text('Notes')->nullable();
            $table->timestamps();
        });
    }
}
