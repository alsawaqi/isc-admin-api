<?php

namespace Tests\Unit\Orders;

use App\Http\Controllers\OrdersPlacedController;
use App\Models\User;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class FulfillmentTransitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Storage::fake('uploads');
        Auth::setUser((new User)->forceFill(['id'=>1, 'User_Name'=>'Test operator']));
        $this->mock(CustomerNotificationService::class)->shouldReceive('notifyOrderStatus')->andReturnNull();
        Schema::create('Orders_Placed_T', function (Blueprint $t) {
            $t->id(); $t->string('Status'); $t->string('Payment_Method');
            $t->string('Payment_Status'); $t->string('Delivery_Type'); $t->timestamps();
        });
        Schema::create('Orders_Placed_Details_T', function (Blueprint $t) {
            $t->id(); $t->integer('Orders_Placed_Id'); $t->string('Status'); $t->timestamps();
        });
        Schema::create('Sales_Transaction_Header_T', function (Blueprint $t) {
            $t->id(); $t->integer('Orders_Placed_Id');
        });
        Schema::create('Order_Process_Log_T', function (Blueprint $t) {
            $t->id(); $t->integer('Orders_Placed_Id');
            foreach (['Step_Code','Status','Actor_Name','Actor_Role','Signature_Url','Signature_Mime','Notes'] as $column) $t->string($column)->nullable();
            $t->integer('Actor_User_Id')->nullable(); $t->boolean('Is_External');
            $t->timestamp('Signed_At')->nullable(); $t->timestamps();
        });
    }

    private function seedOrder(string $status, string $delivery = 'pickup'): void
    {
        DB::table('Orders_Placed_T')->insert(['id'=>1,'Status'=>$status,'Payment_Method'=>'cod','Payment_Status'=>'pending','Delivery_Type'=>$delivery]);
        DB::table('Orders_Placed_Details_T')->insert(['id'=>1,'Orders_Placed_Id'=>1,'Status'=>$status]);
    }

    private function perform(string $action)
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9ZkAAAAASUVORK5CYII=');
        $request = Request::create('/', 'POST', ['note'=>'Test only'], [], ['signature'=>UploadedFile::fake()->createWithContent('test.png', $png)]);
        return app(OrdersPlacedController::class)->{$action}($request, 1);
    }

    public static function invalidStages(): array
    {
        return [
            ['packing','delivered'], ['packing','cancelled'], ['packing','returned'],
            ['packing','packed'], ['packing','ready_for_collection'], ['packing','on-hold'],
            ['dispatch','pending'], ['dispatch','delivered'], ['dispatch','shipped'], ['dispatch','ready_for_collection'],
            ['shipment','pending'], ['shipment','packed'], ['shipment','delivered'], ['shipment','shipped'],
        ];
    }

    #[DataProvider('invalidStages')]
    public function test_invalid_or_repeated_stage_has_no_database_or_file_side_effects(string $action, string $status): void
    {
        $this->seedOrder($status, 'delivery');
        try {
            $response = $this->perform($action);
            $this->fail('Expected HTTP 409; got '.$response->getStatusCode());
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame($status, DB::table('Orders_Placed_T')->value('Status'));
        $this->assertSame($status, DB::table('Orders_Placed_Details_T')->value('Status'));
        $this->assertSame(0, DB::table('Order_Process_Log_T')->count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public static function allowedStages(): array
    {
        return [['packing','pending','packed','pickup'], ['packing','processing','packed','delivery'],
            ['dispatch','packed','ready_for_collection','pickup'], ['dispatch','packed','dispatched','delivery'],
            ['shipment','dispatched','shipped','delivery']];
    }

    #[DataProvider('allowedStages')]
    public function test_valid_transition_keeps_cancelled_lines_closed(string $action, string $before, string $after, string $delivery): void
    {
        $this->seedOrder($before, $delivery);
        DB::table('Orders_Placed_Details_T')->insert(['id'=>2,'Orders_Placed_Id'=>1,'Status'=>'cancelled']);
        $this->assertSame(200, $this->perform($action)->getStatusCode());
        $this->assertSame($after, DB::table('Orders_Placed_T')->value('Status'));
        $this->assertSame($after, DB::table('Orders_Placed_Details_T')->where('id',1)->value('Status'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_Details_T')->where('id',2)->value('Status'));
        $this->assertSame(1, DB::table('Order_Process_Log_T')->count());
    }

    public function test_packing_failure_rolls_back_status_and_removes_signature(): void
    {
        $this->seedOrder('pending');
        $event = 'eloquent.creating: App\\Models\\OrderProcessLog';
        Event::listen($event, function () { throw new \RuntimeException('Simulated audit write failure'); });
        try {
            $this->perform('packing');
            $this->fail('Expected the audit failure to abort packing');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit write failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Status'));
        $this->assertSame('pending', DB::table('Orders_Placed_Details_T')->value('Status'));
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_held_line_blocks_whole_order_progress_without_overwriting_the_hold(): void
    {
        $this->seedOrder('pending');
        DB::table('Orders_Placed_Details_T')->where('id', 1)->update(['Status'=>'on-hold']);
        try {
            $this->perform('packing');
            $this->fail('Expected held line to block packing');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('on-hold', DB::table('Orders_Placed_Details_T')->value('Status'));
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }
}
