<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\OrdersPlaced;
use Illuminate\Http\Request;
 
use App\Models\OrderProcessLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Models\OrderPackageDetails;
use App\Models\OrdersPlacedDetails;
use Illuminate\Support\Facades\Auth;
use App\Models\SalesTransactionHeader;
use App\Models\SalesTransactionDetails;
use App\Services\Orders\OrderReturnRefundService;
use App\Services\Orders\UnpaidAmwalOrderCancellationService;
use App\Services\Payments\OfflinePaymentConfirmationService;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Models\OrdersPlacedDetailsCancelled;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

class OrdersPlacedController extends Controller
{
    /**
     * Vendors_Master_T columns safe to expose on order responses (staff need
     * enough contact info to ask the vendor to bring items for packing).
     * NEVER widen this with Bank_* / payout / approval columns.
     */
    private const VENDOR_PUBLIC_COLUMNS = 'id,Vendor_Code,Vendor_Name,Trade_Name,Email_1,Phone_No,Contact_Person_Name,Contact_Person_Title,Contact_Person_Email,Contact_Person_Phone';

    /**
     * Add a cheap has_vendor_items boolean to a stage-list query: true when the
     * order has at least one line owned by a vendor (Vendor_Id not null on
     * Orders_Placed_Details_T). Done as a scalar subquery because withExists()
     * compiles to `exists(...)` in the select list, which SQL Server rejects.
     */
    private function addHasVendorItemsFlag($query)
    {
        return $query->addSelect([
            'has_vendor_items' => DB::table('Orders_Placed_Details_T')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')
                ->whereColumn('Orders_Placed_Details_T.Orders_Placed_Id', 'Orders_Placed_T.id')
                ->whereNotNull('Orders_Placed_Details_T.Vendor_Id'),
        ])->withCasts(['has_vendor_items' => 'boolean']);
    }


    public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = OrdersPlaced::query();


        $query->with(['customerContact', 'shipper', 'location']);

        // search by name
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('Transaction_Number', $search)
                    ->orWhereHas('customerContact', function ($q2) use ($search) {
                        $q2->where('Contact_Person_Name', 'like', "%{$search}%")
                            ->orWhere('Telephone', $search);
                    });
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Transaction_Number', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        $query->where('Status', 'pending');
        $query->where(function ($visible) {
            $visible->whereNull('Payment_Method')
                ->orWhere('Payment_Method', '<>', 'card')
                ->orWhere('Payment_Status', 'paid');
        });

        $this->addHasVendorItemsFlag($query);

        // return paginator (includes data + links + total + current_page)
        return response()->json(
            $query->paginate($perPage)
        );
    }


    public function index_customer(Request $request)
    {
        $customerId = $request->customers_id;

        $customer = Customers::find($customerId);

        $sortBy     = $request->query('sortBy', 'id');      // default
        $sortDir    = $request->query('sortDir', 'desc');   // default
        $perPage    = (int) $request->query('per_page', 10);

        $query = OrdersPlaced::query();

        $query->with(['customerContact', 'shipper', 'location']);

        if (!empty($customerId)) {
            $query->where('Customers_Id', $customerId);
        }

        $query->where(function ($visible) {
            $visible->whereNull('Payment_Method')
                ->orWhere('Payment_Method', '<>', 'card')
                ->orWhereIn('Payment_Status', [
                    'paid',
                    'paid_requires_review',
                    'refunded',
                    'partially_refunded',
                ]);
        });

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Transaction_Number', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);


        return response()->json([
            'data' => $query->paginate($perPage),
            'customer' => $customer
        ]);
    }

    public function packing_index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = OrdersPlaced::query();


        $query->with(['customerContact', 'shipper', 'location']);

        // search by name
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('Transaction_Number', $search)
                    ->orWhereHas('customerContact', function ($q2) use ($search) {
                        $q2->where('Contact_Person_Name', 'like', "%{$search}%")
                            ->orWhere('Telephone', $search);
                    });
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Transaction_Number', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        $query->where('Status', 'packed');

        $this->addHasVendorItemsFlag($query);

        // return paginator (includes data + links + total + current_page)
        return response()->json(
            $query->paginate($perPage)
        );
    }


    public function dispatch_index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = OrdersPlaced::query();


        $query->with(['customerContact', 'shipper', 'location']);

        // search by name
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('Transaction_Number', $search)
                    ->orWhereHas('customerContact', function ($q2) use ($search) {
                        $q2->where('Contact_Person_Name', 'like', "%{$search}%")
                            ->orWhere('Telephone', $search);
                    });
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Transaction_Number', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        $query->where('Delivery_Type', 'ship');
        $query->whereIn('Status', ['dispatched', 'processing']);

        $this->addHasVendorItemsFlag($query);

        // return paginator (includes data + links + total + current_page)
        return response()->json(
            $query->paginate($perPage)
        );
    }


    public function shipment_index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = OrdersPlaced::query();


        $query->with(['customerContact', 'shipper', 'location']);

        // search by name
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('Transaction_Number', $search)
                    ->orWhereHas('customerContact', function ($q2) use ($search) {
                        $q2->where('Contact_Person_Name', 'like', "%{$search}%")
                            ->orWhere('Telephone', $search);
                    });
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Transaction_Number', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        $query->where('Status', 'shipped');
        $query->where('Delivery_Type', 'ship');

        $this->addHasVendorItemsFlag($query);

        // return paginator (includes data + links + total + current_page)
        return response()->json(
            $query->paginate($perPage)
        );
    }


    public function pickup_index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $sortDir  = $request->query('sortDir', 'desc');
        $perPage  = (int) $request->query('per_page', 10);

        $query = OrdersPlaced::query();
        $query->with(['customerContact', 'shipper', 'location']);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('Transaction_Number', $search)
                    ->orWhere('Order_Code', 'like', "%{$search}%")
                    ->orWhereHas('customerContact', function ($q2) use ($search) {
                        $q2->where('Contact_Person_Name', 'like', "%{$search}%")
                            ->orWhere('Telephone', $search);
                    });
            });
        }

        if (! in_array($sortBy, ['id', 'Transaction_Number', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);
        $query->where('Delivery_Type', 'pickup');
        $query->where('Status', 'ready_for_collection');

        $this->addHasVendorItemsFlag($query);

        // return paginator (includes data + links + total + current_page)
        return response()->json(
            $query->paginate($perPage)
        );
    }


    public function delivered_index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $status   = $request->query('status');    // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = OrdersPlaced::query();


        $query->with([
            'customerContact',
            'shipper',
            'location',
            'orderlist:id,Orders_Placed_Id,Quantity,Status,Returned_Quantity,Refunded_Amount,Net_Amount,Return_State,Refund_State',
            'transaction.details',
            // Public contact columns only — never expose Bank_* here.
            'vendorOrders.vendor:' . self::VENDOR_PUBLIC_COLUMNS,
        ])->withCount('orderlist')
            ->withSum('orderlist as total_quantity', 'Quantity');

        // search by name
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('Transaction_Number', 'like', "%{$search}%")
                    ->orWhere('Order_Code', 'like', "%{$search}%")
                    ->orWhereHas('customerContact', function ($q2) use ($search) {
                        $q2->where('Contact_Person_Name', 'like', "%{$search}%")
                            ->orWhere('Telephone', $search);
                    });
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Transaction_Number', 'Order_Code', 'Total_Price', 'Status', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);


        // "View All Orders": a blank (or 'all') status returns every order regardless
        // of fulfillment stage; a specific status narrows the list to that status only.
        if (!empty($status) && $status !== 'all') {
            $query->where('Status', $status);
        }

        // Provisional/failed/cancelled card checkout rows are payment audit
        // records, not commerce orders. Use the central visibility policy so
        // "View All Orders" stays aligned with sales and dashboard surfaces.
        $query->actualCommerceOrder();

        $this->addHasVendorItemsFlag($query);


        // return paginator (includes data + links + total + current_page)
        return response()->json(
            $query->paginate($perPage)
        );
    }



    public function show($id)
    {
        $order = OrdersPlaced::with([
            'customerContact',
            'shipper',
            'location',
            'orderlist',
            'orderlist.product',
            // Item ownership: expose only the vendor's public contact info
            // (no Bank_* columns) so staff can notify the vendor for packing.
            'orderlist.product.vendor:' . self::VENDOR_PUBLIC_COLUMNS,
            'vendorOrders',
            // Public contact columns only — never expose Bank_* here.
            'vendorOrders.vendor:' . self::VENDOR_PUBLIC_COLUMNS,
            'transaction',
            'transaction.details'
        ])->findOrFail($id);

        // Invoice number lives on Sales_Transaction_Header_T (written at
        // checkout); alias it so print views don't dig into the relation.
        // Null-safe: orders without a transaction row get invoice_no = null.
        $order->setAttribute('invoice_no', optional($order->transaction)->Invoice_No);

        // Admin detail pages render customer_contact.title_name || Designation;
        // new storefront addresses carry only Title_Id, so resolve the name here.
        $this->attachContactTitleName($order->customerContact);

        return response()->json($order);
    }

    /**
     * Attach a null-safe title_name attribute to a customer contact, resolved
     * from Titles_Master_T via Title_Id (mirrors laravel-api's attachTitleNames).
     * Guarded: pre-migration (no Title_Id column / no titles table) => null.
     */
    private function attachContactTitleName($contact): void
    {
        if (! $contact) {
            return;
        }

        $titleName = null;

        if (Schema::hasColumn('Customers_Contact_T', 'Title_Id')
            && Schema::hasTable('Titles_Master_T')
            && $contact->Title_Id) {
            $titleName = DB::table('Titles_Master_T')
                ->where('id', $contact->Title_Id)
                ->value('Title_Name');
        }

        $contact->setAttribute('title_name', $titleName);
    }

    private function storeOrderSignature(UploadedFile $file, int $orderId): array
    {
        if (! $file->isValid()) {
            throw new \RuntimeException('Invalid signature upload.');
        }

        $dir = "signatures/orders/{$orderId}";
        $path = Storage::disk('r2')->putFile($dir, $file, 'public');

        if (! $path) {
            throw new \RuntimeException('Signature could not be saved.');
        }

        $publicUrl = rtrim(config('filesystems.disks.r2.url'), '/') . '/' . ltrim($path, '/');

        return [
            'path' => $path,
            'url' => $publicUrl,
            'mime' => $file->getMimeType() ?? 'image/png',
        ];
    }

    private function selectedLineIdsFromRequest(Request $request)
    {
        $selected = $request->input('selected_lines', []);

        if (is_string($selected)) {
            $decoded = json_decode($selected, true);
            $selected = json_last_error() === JSON_ERROR_NONE
                ? $decoded
                : array_filter(array_map('trim', explode(',', $selected)));
        }

        if (!is_array($selected)) {
            $selected = [$selected];
        }

        return collect($selected)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
    }


    public function packing(Request $request, $id)
    {
        // Validate before the transaction/try so a failure returns 422 (the
        // catch (\Throwable) below would otherwise swallow ValidationException into a 500).
        $request->validate([
            'signature' => ['required', 'file', 'image', 'max:5120'],
            'note'      => ['nullable', 'string', 'max:2000'],
        ]);

        $order        = OrdersPlaced::findOrFail($id);
        $orderDetails = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();
        $this->ensurePaymentAllowsFulfillment($order);

        return DB::transaction(function () use ($request, $order, $orderDetails) {
            try {
                if (! $request->hasFile('signature')) {
                    throw new \RuntimeException('No signature file received.');
                }

                $signature = $this->storeOrderSignature($request->file('signature'), $order->id);

                // 3) Update order + details status → packed
                $order->update(['Status' => 'packed']);

                foreach ($orderDetails as $detail) {
                    $detail->update(['Status' => 'packed']);
                }

                // 4) Log process
                OrderProcessLog::create([
                    'Orders_Placed_Id' => $order->id,
                    'Step_Code'        => 'PACKING_CONFIRMED',
                    'Status'           => 'Packed',
                    'Is_External'      => false,

                    'Actor_User_Id'    => Auth::id(),
                    'Actor_Name'       => Auth::user()->User_Name ?? 'System',
                    'Actor_Role'       => optional(Auth::user())->role ?? null,

                    'Signed_At'        => now(),
                    'Signature_Url'    => $signature['url'],
                    'Signature_Mime'   => $signature['mime'],
                    'Notes'            => $request->input('note') ?: null,
                ]);

                $this->notifyCustomerOrderStatus($order->fresh(), 'packed');

                return response()->json([
                    'message'       => 'Packing confirmed.',
                    'order_id'      => $order->id,
                    'signature_url' => $signature['url'],
                ]);
            } catch (\Throwable $e) {
                Log::error('Error confirming packing', [
                    'order_id'  => $order->id,
                    'message'   => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'message' => 'Error confirming packing',
                    'error'   => $e->getMessage(),
                ], 500);
            }
        });
    }

    public function confirmOfflinePayment(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor && $actor->can('order verification'),
            403,
            'You are not allowed to verify bank-transfer payments.',
        );

        $order = OrdersPlaced::query()->findOrFail($id);
        $method = strtolower((string) $order->Payment_Method);
        abort_unless(
            $method === 'transfer',
            409,
            'COD is confirmed only by the delivery or pickup handover workflow.',
        );

        $rules = [
            'note' => ['required', 'string', 'max:300'],
            'signature' => ['required', 'file', 'image', 'max:5120'],
            'transfer_reference' => ['required', 'string', 'max:120'],
        ];
        $validated = $request->validate($rules);

        $signature = $this->storeOrderSignature($request->file('signature'), (int) $id);

        try {
            $result = app(OfflinePaymentConfirmationService::class)->confirm(
                orderId: (int) $id,
                actorId: (int) $actor->id,
                actorName: $actor->User_Name ?? $actor->name ?? 'Admin',
                actorRole: method_exists($actor, 'getRoleNames')
                    ? $actor->getRoleNames()->first()
                    : null,
                note: $validated['note'],
                transferReference: $validated['transfer_reference'] ?? null,
                signature: $signature,
            );

            if ($result['idempotent'] && ! empty($signature['path'])) {
                Storage::disk('r2')->delete($signature['path']);
            }
        } catch (\Throwable $exception) {
            if (! empty($signature['path'])) {
                Storage::disk('r2')->delete($signature['path']);
            }
            throw $exception;
        }

        return response()->json([
            'message' => $result['idempotent']
                ? 'This payment was already confirmed.'
                : 'Payment confirmed and loyalty points settled.',
            'data' => $result,
        ]);
    }




    public function cancel(Request $request, $id): JsonResponse
    {
        $order = OrdersPlaced::findOrFail($id);
        $this->ensureCapturedAmwalRefundIsHandledExternally($order);

        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'signature' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $selectedLineIds = $this->selectedLineIdsFromRequest($request);

        if ($this->isAmwalOrder($order)) {
            if ($selectedLineIds->isNotEmpty()) {
                return response()->json([
                    'message' => 'An unpaid AmwalPay order must be cancelled in full because its signed amount covers the complete order.',
                ], 409);
            }

            $signature = $this->storeOrderSignature($request->file('signature'), $order->id);
            $actor = Auth::user();
            $result = app(UnpaidAmwalOrderCancellationService::class)->cancel(
                orderId: (int) $order->id,
                actorId: (int) Auth::id(),
                actorName: $actor?->User_Name ?? $actor?->name ?? 'System',
                actorRole: $actor?->role ?? 'accounting',
                signature: $signature,
                note: $request->input('note'),
            );

            $freshOrder = $order->fresh();
            $this->notifyCustomerOrderStatus($freshOrder, (string) $freshOrder->Status);

            return response()->json([
                'ok' => true,
                'payment_status' => $freshOrder->Payment_Status,
                'released_lines' => $result['released_lines'],
                'released_loyalty_points' => $result['released_loyalty_points'],
                'cart_restoration' => $result['cart_restoration'],
                'idempotent' => $result['idempotent'],
            ]);
        }

        DB::transaction(function () use ($order, $selectedLineIds, $request) {

            // 1) Decide which details to cancel
            if ($selectedLineIds->isEmpty()) {
                // Cancel ALL currently active details for this order
                $detailsToCancel = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)
                    ->where('Status', '!=', 'cancelled')
                    ->get();
            } else {
                // Cancel ONLY selected lines that belong to this order and are not cancelled yet
                $detailsToCancel = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)
                    ->whereIn('id', $selectedLineIds)
                    ->where('Status', '!=', 'cancelled')
                    ->get();
            }


            // 2) Get signature file
            if (! $request->hasFile('signature')) {
                throw new \RuntimeException('No signature file received.');
            }

            $signature = $this->storeOrderSignature($request->file('signature'), $order->id);

            // 2) Apply cancellation + write to "cancelled" table + process log
            foreach ($detailsToCancel as $detail) {
                // update detail status
                $detail->update(['Status' => 'cancelled']);

                // create "cancelled detail" record
                $cancelledDetail = OrdersPlacedDetailsCancelled::create([
                    'Orders_Placed_Details_Id' => $detail->id,
                    'Orders_Placed_Id'         => $order->id,
                    'Cancelled_By_Users_Id'    => Auth::id(),
                    'Cancellation_Reason'      => $request->input('note'),
                ]);

                // process log linked to this cancelled detail
                OrderProcessLog::create([
                    'Orders_Placed_Id'                     => $order->id,
                    'Orders_Placed_Details_Id'             => $detail->id,
                    'Orders_Placed_Details_Cancelled_Id'   => $cancelledDetail->id,
                    'Step_Code'                            => 'CANCELLED',
                    'Status'                               => 'Cancelled',
                    'Is_External'                          => 0,
                    'Actor_User_Id'                        => Auth::id(),
                    'Actor_Name'                           => Auth::user()?->name ?? 'System',
                    'Actor_Role'                           => 'accounting',
                    'Signed_At'                            => now(),

                    'Signature_Url'    => $signature['url'],
                    'Signature_Mime'   => $signature['mime'],
                    'Notes'                                => $request->input('note'),
                ]);
            }

            // 3) After cancelling, check if ANY active details remain
            $hasActiveLines = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)
                ->where('Status', '!=', 'cancelled')
                ->exists();

            // If no active lines → cancel parent order too
            if (! $hasActiveLines) {
                $order->update(['Status' => 'cancelled']);
            }
        });

        $this->notifyCustomerOrderStatus($order->fresh(), (string) $order->fresh()->Status);

        return response()->json(['ok' => true]);
    }

    public function returnRefund(Request $request, $id): JsonResponse
    {
        if (is_string($request->input('items'))) {
            $decoded = json_decode($request->input('items'), true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['items' => $decoded]);
            }
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'signature' => ['required', 'file', 'image', 'max:5120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_detail_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.refund_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.restock' => ['nullable', 'boolean'],
            'items.*.reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = OrdersPlaced::findOrFail($id);
        $this->ensureAmwalReturnIsHandledThroughGateway($order);
        $signature = $this->storeOrderSignature($request->file('signature'), $order->id);

        $result = app(OrderReturnRefundService::class)->apply(
            order: $order,
            items: $validated['items'],
            signature: $signature,
            actor: Auth::user(),
            note: $validated['note'],
        );

        $this->notifyCustomerReturnRefund($order->fresh(), $result);

        return response()->json([
            'ok' => true,
            'message' => 'Return/refund adjustment saved.',
            'data' => $result,
        ]);
    }



    public function dispatch(Request $request, $id)
    {
        $request->validate([
            'files'     => ['array'],           // files[DETAIL_ID][] (optional)
            'files.*'   => ['array'],
            'files.*.*' => ['file', 'image', 'max:5120'],

            // 🔹 signature as real uploaded image file
            'signature' => ['required', 'file', 'image', 'max:5120'],
            'note'      => ['nullable', 'string', 'max:2000'],
        ]);

        $order = OrdersPlaced::findOrFail($id);
        $this->ensurePaymentAllowsFulfillment($order);
        $orderplacedetail = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();

        try{
            
      

        return DB::transaction(function () use ($request, $order, $orderplacedetail) {
            // 1) Save any photos to Orders_Packaging_Details_T
            $evidence = [];

            foreach ((array) $request->file('files', []) as $detailId => $files) {
                foreach ((array) $files as $file) {
                    $dir  = "orders/packaging/{$order->id}/{$detailId}";
                    $name = 'packed_' . now()->format('Ymd_His') . '_' . Str::random(5)
                        . '.' . ($file->getClientOriginalExtension() ?: 'jpg');

                    $path = $file->storeAs($dir, $name);   // returns relative path

                    $row = OrderPackageDetails::create([
                        'Packaging_Code'           => 'PKG-' . Str::upper(Str::random(4)),
                        'Orders_Placed_Id'         => $order->id,
                        'Orders_Placed_Details_Id' => (int) $detailId,
                        'Packed_Image'             => $path,      // 👈 path only
                        'Packed_By'                => Auth::id(),
                    ]);

                    $evidence[] = $row->Packed_Image;
                }
            }


            $signature = $this->storeOrderSignature($request->file('signature'), $order->id);
            $isPickup = strtolower((string) $order->Delivery_Type) === 'pickup';
            $nextStatus = $isPickup ? 'ready_for_collection' : 'dispatched';
            $stepCode = $isPickup ? 'PICKUP_READY_FOR_COLLECTION' : 'DISPATCH_READY';
            $statusLabel = $isPickup ? 'ready_for_collection' : 'dispatched';

            // 3) Log in OrderProcessLog
            OrderProcessLog::create([
                'Orders_Placed_Id'  => $order->id,
                'Step_Code'         => $stepCode,
                'Status'            => $statusLabel,
                'Actor_User_Id'     => Auth::id(),
                'Actor_Name'        => Auth::user()->User_Name ?? 'System',
                'Actor_Role'        => optional(Auth::user())->role,
                'Is_External'       => false,

                'Notes'             => $request->input('note') ?: null,
                'Signature_Url'     => $signature['url'],
                'Signature_Mime'    => $signature['mime'],
                'Signed_At'         => now(),
            ]);

            // 4) Flip status by fulfillment type.
            $order->update(['Status' => $nextStatus]);
            foreach ($orderplacedetail as $detail) {
                $detail->update(['Status' => $nextStatus]);
            }

            $this->notifyCustomerOrderStatus($order->fresh(), $nextStatus);

            return response()->json([
                'message'        => $isPickup
                    ? 'Pickup order marked ready for collection.'
                    : 'Order dispatched to shipment.',
                'order_id'       => $order->id,
                'status'         => $nextStatus,
                'signature_url'  => $signature['url'],
                'evidence_count' => count($evidence),
            ]);
        });


                }catch(\Exception $e){
                    return response()->json(['message' => 'Error confirming dispatch', 'error' => $e->getMessage()], 500);
                }
    }



    /**
     * Minimal helper: accept either uploaded file or data URL string.
     */
    protected function saveSignature(int $orderId, ?UploadedFile $file = null, ?string $dataUrl = null): array
    {
        // 🔧 choose the disk you actually use for signatures:
        //   'public'  → /storage/...
        //   'r2'      → Cloudflare R2 (if configured)
        $disk = 'public'; // change to 'r2' if needed

        $dir = "signatures/orders/{$orderId}";
        Storage::disk($disk)->makeDirectory($dir);

        // 1) If a normal uploaded file is provided (e.g. phone camera file)
        if ($file instanceof UploadedFile) {
            $ext  = $file->getClientOriginalExtension() ?: 'png';
            $mime = $file->getClientMimeType() ?: 'image/' . $ext;

            $filename = 'signature_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . $ext;
            $path     = "{$dir}/{$filename}";

            Storage::disk($disk)->putFileAs($dir, $file, $filename, ['visibility' => 'public']);

            $url = $disk === 'public' ? Storage::url($path) : Storage::disk($disk)->path($path);

            return [$path, $url, $mime];
        }

        // 2) Otherwise, expect a SignaturePad data URL string
        if (!$dataUrl || !preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $dataUrl, $m)) {
            throw ValidationException::withMessages([
                'signature' => 'Invalid signature data URL.',
            ]);
        }

        $ext  = $m[1] === 'jpg' ? 'jpeg' : $m[1];
        $mime = 'image/' . $ext;

        $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));
        if ($binary === false) {
            throw ValidationException::withMessages([
                'signature' => 'Could not decode signature image.',
            ]);
        }

        $filename = 'signature_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . $ext;
        $path     = "{$dir}/{$filename}";

        Storage::disk($disk)->put($path, $binary, 'public');

        $url = $disk === 'public' ? Storage::url($path) : Storage::disk($disk)->path($path);

        return [$path, $url, $mime];
    }

    // Inside OrdersPlacedController
    public function shipment(Request $request, $id)
    {
        // Validate request
        $request->validate([
            'signature' => ['required', 'file', 'image', 'max:5120'],    // SignaturePad data URL
            'note'      => ['nullable', 'string', 'max:2000'],
        ]);

        $order = OrdersPlaced::findOrFail($id);
        $this->ensurePaymentAllowsFulfillment($order);
        $orderDetails = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();
        try {
            if (strtolower((string) $order->Delivery_Type) === 'pickup') {
                return response()->json([
                    'message' => 'Pickup orders must be completed from the pickup collection page.',
                ], 422);
            }


            return DB::transaction(function () use ($request, $order, $orderDetails) {



                // 2) Get signature file
                if (! $request->hasFile('signature')) {
                    throw new \RuntimeException('No signature file received.');
                }

                $signature = $this->storeOrderSignature($request->file('signature'), $order->id);




                // 2) Update statuses → shipped
                $order->update(['Status' => 'shipped']);

                foreach ($orderDetails as $detail) {
                    $detail->update(['Status' => 'shipped']);
                }

                // 3) Log shipment in Order_Process_Log_T
                OrderProcessLog::create([
                    'Orders_Placed_Id' => $order->id,
                    'Step_Code'        => 'SHIPMENT_CONFIRMED',
                    'Status'           => 'shipped',
                    'Is_External'      => false,

                    'Actor_User_Id'    => Auth::id(),
                    'Actor_Name'       => optional(Auth::user())->User_Name ?? 'System',
                    'Actor_Role'       => optional(Auth::user())->role ?? null,

                    'Signed_At'        => now(),

                    // 👇 store ONLY the storage path in DB
                    'Signature_Url'    => $signature['url'],
                    'Signature_Mime'   => $signature['mime'],
                    'Notes'            => $request->input('note')  ?: null,
                ]);

                $this->notifyCustomerOrderStatus($order->fresh(), 'shipped');

                return response()->json([
                    'message'       => 'Shipment confirmed.',
                    'order_id'      => $order->id,


                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error confirming shipment', 'error' => $e->getMessage()], 500);
        }
    }




    public function overview($id)
    {
        $order = OrdersPlaced::with([
            'customerContact',
            'shipper',
            'location',
            // Scoped to public contact columns only — never expose Bank_* here.
            'orderlist.product.vendor:' . self::VENDOR_PUBLIC_COLUMNS,
            'orderlist.product.department',
            'orderlist.product.subDepartment',
            'orderlist.product.subSubDepartment',
            'orderlist.adjustments',
            // Public contact columns only — never expose Bank_* here.
            'vendorOrders.vendor:' . self::VENDOR_PUBLIC_COLUMNS,
            'transaction.details',              // transaction + lines
            'packagingDetails.packedBy:id,User_Name',
            'processLogs.actor:id,User_Name',
        ])->findOrFail($id);

        // Group package photos by detail id
        $packagesByDetail = $order->packagingDetails
            ->groupBy('Orders_Placed_Details_Id')
            ->map(function ($rows) {
                return $rows->map(function ($r) {
                    return [
                        'id'            => $r->id,
                        'detail_id'     => $r->Orders_Placed_Details_Id,
                        'unpacked'      => $r->Unpacked_Image,
                        'packed'        => $r->Packed_Image,
                        'packed_by'     => optional($r->packedBy)->User_Name,
                        'created_at'    => $r->created_at,
                    ];
                })->values();
            });

        // Process log (sorted oldest→newest)
        $logs = $order->processLogs
            ->sortBy('created_at')
            ->values()
            ->map(function ($l) {
                return [
                    'id'         => $l->id,
                    'detail_id'  => $l->Orders_Placed_Details_Id,
                    'step_code'  => $l->Step_Code,
                    'status'     => $l->Status,
                    'actor'      => [
                        'id'   => $l->Actor_User_Id,
                        'name' => $l->Actor_Name ?: optional($l->actor)->User_Name,

                    ],
                    'notes'      => $l->Notes,
                    'signature'  => [
                        'url'   => $l->Signature_Url,
                        'mime'  => $l->Signature_Mime,
                        'when'  => $l->Signed_At,
                    ],
                    'evidence'   => $this->decodeEvidence($l->Evidence_Photos),
                    'created_at' => $l->created_at,
                ];
            });

        // Admin detail pages render customer_contact.title_name || Designation;
        // new storefront addresses carry only Title_Id, so resolve the name here.
        $this->attachContactTitleName($order->customerContact);

        return response()->json([
            'order'             => $order->only([
                'id',
                'Order_Code',
                'Transaction_Number',
                'Status',
                'created_at',
                'Delivery_Type',
                'Location_Id',
                'Shippers_Id',
                'Shippers_Destination_Id',
                'Shipping_Basis',
                'Shipping_Weight_Kg',
                'Shipping_Volume_Cbm',
                'Shipping_Price',
                'Shipping_Currency',
                'Sub_Total_Price',
                'Original_Sub_Total_Price',
                'Product_Discount_Amount',
                'Loyalty_Points_Redeemed',
                'Loyalty_Discount_Amount',
                'VAT',
                'Tax',
                'Total_Price',
                // Pickup handover (null pre-migration / for non-pickup orders).
                'Pickup_Person_Name',
                'Pickup_Person_Contact',
                'Pickup_Id_Image_Path',
                'Picked_Up_At',
                'Picked_Up_By',
            ]),
            // Null-safe: orders without a Sales_Transaction_Header_T row => null.
            'invoice_no'        => optional($order->transaction)->Invoice_No,
            'customer_contact'  => $order->customerContact,
            'shipper'           => $order->shipper,
            'location'          => $order->location,
            'details'           => $order->orderlist,          // with product
            'vendor_orders'     => $order->vendorOrders,
            'transaction'       => $order->transaction,        // with details
            'packages_by_detail' => $packagesByDetail,          // grouped
            'logs'              => $logs,                      // timeline
        ]);
    }

    private function decodeEvidence($json)
    {
        if (!$json) return [];
        try {
            return json_decode($json, true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }


    public function complete($id)
    {
        $actor = Auth::user();
        abort_unless(
            $actor && $actor->canAny(['order shipments', 'order delivery']),
            403,
            'You are not allowed to complete delivered orders.',
        );

        $result = DB::transaction(function () use ($id) {
            $order = OrdersPlaced::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                strtolower((string) ($order->Delivery_Type ?? '')) === 'pickup',
                409,
                'Pickup orders must be completed through the pickup handover workflow.',
            );
            abort_unless(
                strtolower((string) ($order->Status ?? '')) === 'shipped',
                409,
                'Only a shipped order can be marked delivered.',
            );
            $this->ensurePaymentAllowsFulfillment($order);

            $orderDetails = OrdersPlacedDetails::query()
                ->where('Orders_Placed_Id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // COD settlement requires the order-level delivered state. Keep
            // detail adjustment markers intact until the settlement service
            // has locked and validated them; a failure rolls this update back.
            $order->update(['Status' => 'delivered']);
            $paymentSettlement = $this->confirmCodAtHandover(
                $order,
                'COD collected when delivery was completed.',
            );

            foreach ($orderDetails as $detail) {
                if (in_array(strtolower((string) $detail->Status), ['cancelled', 'returned'], true)) {
                    continue;
                }
                $detail->update(['Status' => 'delivered']);
            }

            OrderProcessLog::create([
                'Orders_Placed_Id' => $order->id,
                'Step_Code'        => 'ORDER_COMPLETED',
                'Status'           => 'delivered',
                'Is_External'      => false,
                'Actor_User_Id'    => Auth::id(),
                'Actor_Name'       => optional(Auth::user())->User_Name ?? 'System',
                'Actor_Role'       => optional(Auth::user())->role ?? null,
                'Notes'            => 'Order marked complete.',
            ]);

            return [
                'order_id' => (int) $order->id,
                'payment_settlement' => $paymentSettlement,
            ];
        });

        $order = OrdersPlaced::findOrFail($result['order_id']);
        $this->notifyCustomerOrderStatus($order, 'delivered');

        return response()->json([
            'message' => 'Order completed.',
            'order_id' => $order->id,
            'status' => 'delivered',
            'loyalty_points_awarded' => $result['payment_settlement']['points_awarded'] ?? 0,
        ]);
    }


    public function pickupComplete(Request $request, $id)
    {
        $actor = $request->user();
        abort_unless(
            $actor && $actor->can('order pickup'),
            403,
            'You are not allowed to complete pickup handovers.',
        );

        $order = OrdersPlaced::where('id', $id)
            ->where('Delivery_Type', 'pickup')
            ->firstOrFail();
        $this->ensurePaymentAllowsFulfillment($order);

        // BACKWARD-COMPAT GUARD: the collector-ID gate only applies once the
        // pickup person columns exist (pre-migration prod keeps old behavior).
        $captureCollector = Schema::hasColumn('Orders_Placed_T', 'Pickup_Person_Name');

        // IDEMPOTENCY GUARD: a delivered/collected pickup order must not be
        // re-completed — it would overwrite the persisted collector audit
        // record, orphan the previous ID image in R2, duplicate the
        // PICKUP_COLLECTED log, and re-notify the customer.
        $alreadyCollected = strcasecmp((string) $order->Status, 'delivered') === 0
            || ($captureCollector && ! empty($order->Pickup_Person_Name));

        if ($alreadyCollected) {
            return response()->json([
                'message'  => 'This pickup order has already been collected.',
                'order_id' => $order->id,
                'status'   => $order->Status,
            ], 409);
        }

        abort_unless(
            strtolower((string) ($order->Status ?? '')) === 'ready_for_collection',
            409,
            'Only an order that is ready for collection can be handed over.',
        );

        $idImagePath = null;

        if ($captureCollector) {
            $request->validate([
                'pickup_person_name'    => ['required', 'string', 'max:255'],
                'pickup_person_contact' => ['required', 'string', 'max:100'],
                'id_image'              => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            ]);

            // Sensitive PII: store as a PRIVATE R2 object (no 'public'
            // visibility — same as vendor documents). Viewable only via the
            // short-lived presigned URL from pickupIdUrl().
            $idImagePath = Storage::disk('r2')->putFile('PickupIds/' . $order->id, $request->file('id_image'));

            if (! $idImagePath) {
                return response()->json(['message' => 'The ID image could not be saved.'], 500);
            }
        }

        try {
            $result = DB::transaction(function () use ($id, $request, $captureCollector, $idImagePath) {
                $order = OrdersPlaced::query()
                    ->whereKey($id)
                    ->where('Delivery_Type', 'pickup')
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensurePaymentAllowsFulfillment($order);

                $alreadyCollected = strcasecmp((string) $order->Status, 'delivered') === 0
                    || ($captureCollector && ! empty($order->Pickup_Person_Name));
                abort_if($alreadyCollected, 409, 'This pickup order has already been collected.');
                abort_unless(
                    strtolower((string) ($order->Status ?? '')) === 'ready_for_collection',
                    409,
                    'Only an order that is ready for collection can be handed over.',
                );

                $orderDetails = OrdersPlacedDetails::query()
                    ->where('Orders_Placed_Id', $order->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $orderUpdate = ['Status' => 'delivered'];
                $notes = 'Customer collected pickup order.';

                if ($captureCollector) {
                    $orderUpdate += [
                        'Pickup_Person_Name' => $request->input('pickup_person_name'),
                        'Pickup_Person_Contact' => $request->input('pickup_person_contact'),
                        'Pickup_Id_Image_Path' => $idImagePath,
                        'Picked_Up_At' => now(),
                        'Picked_Up_By' => Auth::id(),
                    ];

                    $notes .= sprintf(
                        ' Collected by: %s (%s)',
                        $request->input('pickup_person_name'),
                        $request->input('pickup_person_contact')
                    );
                }

                // Preserve adjustment markers until COD settlement validates
                // them. The surrounding transaction rolls back this header
                // update if settlement detects a cancelled/refunded line.
                $order->update($orderUpdate);
                $paymentSettlement = $this->confirmCodAtHandover(
                    $order,
                    'COD collected when the pickup order was handed over.',
                );

                foreach ($orderDetails as $detail) {
                    if (in_array(strtolower((string) $detail->Status), ['cancelled', 'returned'], true)) {
                        continue;
                    }
                    $detail->update(['Status' => 'delivered']);
                }

                OrderProcessLog::create([
                    'Orders_Placed_Id' => $order->id,
                    'Step_Code' => 'PICKUP_COLLECTED',
                    'Status' => 'delivered',
                    'Is_External' => false,
                    'Actor_User_Id' => Auth::id(),
                    'Actor_Name' => optional(Auth::user())->User_Name ?? 'System',
                    'Actor_Role' => optional(Auth::user())->role ?? null,
                    'Notes' => $notes,
                ]);

                return [
                    'order_id' => (int) $order->id,
                    'payment_settlement' => $paymentSettlement,
                ];
            });
        } catch (\Throwable $exception) {
            if ($idImagePath) {
                Storage::disk('r2')->delete($idImagePath);
            }
            throw $exception;
        }

        $order = OrdersPlaced::findOrFail($result['order_id']);

        $this->notifyCustomerOrderStatus($order, 'delivered');

        return response()->json([
            'message'  => 'Pickup order collected.',
            'order_id' => $order->id,
            'status'   => 'delivered',
            'pickup_person_name'    => $captureCollector ? $order->Pickup_Person_Name : null,
            'pickup_person_contact' => $captureCollector ? $order->Pickup_Person_Contact : null,
            'picked_up_at'          => $captureCollector ? $order->Picked_Up_At : null,
            'picked_up_by'          => $captureCollector ? $order->Picked_Up_By : null,
            'loyalty_points_awarded' => $result['payment_settlement']['points_awarded'] ?? 0,
        ]);
    }


    /**
     * Short-lived presigned URL to view the collector's ID image (private R2
     * object). Mirrors VendorController@documentUrl, but with NO public-url
     * fallback: ID copies are sensitive PII and must never be exposed via a
     * permanent public link.
     */
    public function pickupIdUrl($id)
    {
        $order = OrdersPlaced::findOrFail($id);

        $path = Schema::hasColumn('Orders_Placed_T', 'Pickup_Id_Image_Path')
            ? $order->Pickup_Id_Image_Path
            : null;

        if (! $path) {
            return response()->json(['message' => 'This order has no pickup ID image.'], 404);
        }

        $url = Storage::disk('r2')->temporaryUrl($path, now()->addMinutes(10));

        return response()->json([
            'success' => true,
            'url'     => $url,
        ]);
    }


    public function putOnHold(Request $request, $id)
    {
        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = OrdersPlaced::where('id', $id)->firstOrFail();
        $selectedLineIds = $this->selectedLineIdsFromRequest($request);

        $detailsQuery = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)
            ->whereNotIn('Status', ['cancelled', 'on-hold']);

        if ($selectedLineIds->isNotEmpty()) {
            $detailsQuery->whereIn('id', $selectedLineIds->all());
        }

        $detailsToHold = $detailsQuery->get();

        if ($detailsToHold->isEmpty()) {
            return response()->json([
                'message' => 'No eligible order products were selected for hold.',
            ], 422);
        }

        DB::transaction(function () use ($order, $detailsToHold, $request) {
            $previousOrderStatus = $order->Status;

            foreach ($detailsToHold as $detail) {
                $previousLineStatus = $detail->Status;
                $detail->update(['Status' => 'on-hold']);

                OrderProcessLog::create([
                    'Orders_Placed_Id'         => $order->id,
                    'Orders_Placed_Details_Id' => $detail->id,
                    'Step_Code'                => 'LINE_ON_HOLD',
                    'Status'                   => 'on-hold',
                    'Is_External'              => false,
                    'Actor_User_Id'            => Auth::id(),
                    'Actor_Name'               => optional(Auth::user())->User_Name ?? optional(Auth::user())->name ?? 'System',
                    'Actor_Role'               => optional(Auth::user())->role ?? null,
                    'Notes'                    => trim(sprintf(
                        "Previous order status: %s. Previous line status: %s.%s",
                        $previousOrderStatus,
                        $previousLineStatus,
                        $request->filled('note') ? ' Note: ' . $request->input('note') : ''
                    )),
                ]);
            }

            $order->update(['Status' => 'on-hold']);
        });

        $this->notifyCustomerOrderStatus($order->fresh(), 'on-hold');

        return response()->json([
            'message' => 'Selected order products were put on hold.',
            'held_count' => $detailsToHold->count(),
            'order_status' => 'on-hold',
        ]);
    }


    public function removeOnHold(Request $request, $id)
    {
        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'restore_status' => ['nullable', 'string', 'in:pending,processing,packed,dispatched,shipped,ready_for_collection,delivered,returned'],
        ]);

        $order = OrdersPlaced::where('id', $id)->firstOrFail();
        $selectedLineIds = $this->selectedLineIdsFromRequest($request);
        $restoreStatus = $request->input('restore_status') ?: 'pending';

        if ($restoreStatus !== 'pending') {
            $this->ensurePaymentAllowsFulfillment($order);
        }

        $detailsQuery = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)
            ->where('Status', 'on-hold');

        if ($selectedLineIds->isNotEmpty()) {
            $detailsQuery->whereIn('id', $selectedLineIds->all());
        }

        $detailsToRelease = $detailsQuery->get();

        if ($detailsToRelease->isEmpty()) {
            return response()->json([
                'message' => 'No held order products were selected for release.',
            ], 422);
        }

        DB::transaction(function () use ($order, $detailsToRelease, $restoreStatus, $request) {
            foreach ($detailsToRelease as $detail) {
                $detail->update(['Status' => $restoreStatus]);

                OrderProcessLog::create([
                    'Orders_Placed_Id'         => $order->id,
                    'Orders_Placed_Details_Id' => $detail->id,
                    'Step_Code'                => 'LINE_HOLD_RELEASED',
                    'Status'                   => $restoreStatus,
                    'Is_External'              => false,
                    'Actor_User_Id'            => Auth::id(),
                    'Actor_Name'               => optional(Auth::user())->User_Name ?? optional(Auth::user())->name ?? 'System',
                    'Actor_Role'               => optional(Auth::user())->role ?? null,
                    'Notes'                    => trim(sprintf(
                        "Hold released to %s.%s",
                        $restoreStatus,
                        $request->filled('note') ? ' Note: ' . $request->input('note') : ''
                    )),
                ]);
            }

            $hasHeldLines = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)
                ->where('Status', 'on-hold')
                ->exists();

            if (!$hasHeldLines) {
                $order->update(['Status' => $restoreStatus]);
            }
        });

        $this->notifyCustomerOrderStatus($order->fresh(), $restoreStatus);

        return response()->json([
            'message' => 'Selected held products were released.',
            'released_count' => $detailsToRelease->count(),
            'order_status' => $restoreStatus,
        ]);
    }

    private function ensureCardPaymentSettled(OrdersPlaced $order): void
    {
        $saleHeaderIds = SalesTransactionHeader::where('Orders_Placed_Id', $order->id)->pluck('id');
        $salePayments = $saleHeaderIds->isNotEmpty()
            ? SalesTransactionDetails::whereIn('Sales_Transaction_Header_Id', $saleHeaderIds)->get()
            : collect();

        $amwalCardPayments = $salePayments->filter(
            fn ($payment) => strtolower((string) ($payment->Payment_Method ?? '')) === 'card'
                && strtolower((string) ($payment->Payment_Gateway ?? '')) === 'amwal_smartbox'
        );

        $amwalAttempts = Schema::hasTable('Payment_Gateway_Attempts_T')
            ? DB::table('Payment_Gateway_Attempts_T')
                ->where('Orders_Placed_Id', $order->id)
                ->where('Gateway', 'amwal_smartbox')
                ->get(['Status'])
            : collect();

        if ($amwalCardPayments->isEmpty() && $amwalAttempts->isEmpty()) {
            return;
        }

        $orderIsPaid = strtolower((string) ($order->Payment_Status ?? '')) === 'paid';
        $cardPaymentsArePaid = $amwalCardPayments
            ->isNotEmpty()
            && $amwalCardPayments
            ->every(fn ($payment) => strtolower((string) ($payment->Payment_Status ?? '')) === 'paid');
        $hasPaidAttempt = $amwalAttempts->isEmpty()
            || $amwalAttempts->contains(
                fn ($attempt) => strtolower((string) ($attempt->Status ?? '')) === 'paid'
            );
        $hasReviewAttempt = $amwalAttempts->contains(
            fn ($attempt) => strtolower((string) ($attempt->Status ?? '')) === 'paid_requires_review'
        );

        abort_unless(
            $orderIsPaid && $cardPaymentsArePaid && $hasPaidAttempt && ! $hasReviewAttempt,
            409,
            'This card order is awaiting payment reconciliation and cannot advance in fulfillment.',
        );
    }

    private function ensurePaymentAllowsFulfillment(OrdersPlaced $order): void
    {
        $this->ensureCardPaymentSettled($order);

        if (strtolower((string) ($order->Payment_Method ?? '')) !== 'transfer') {
            return;
        }

        abort_unless(
            strtolower((string) ($order->Payment_Status ?? '')) === 'paid',
            409,
            'This bank transfer has not been verified and the order cannot advance in fulfillment.',
        );
    }

    /** @return array<string, mixed>|null */
    private function confirmCodAtHandover(OrdersPlaced $order, string $note): ?array
    {
        if (strtolower((string) ($order->Payment_Method ?? '')) !== 'cod') {
            return null;
        }

        $actor = Auth::user();
        $allowedPermissions = strtolower((string) ($order->Delivery_Type ?? '')) === 'pickup'
            ? ['order pickup']
            : ['order shipments', 'order delivery'];

        abort_unless(
            $actor && $actor->canAny($allowedPermissions),
            403,
            'You are not allowed to confirm COD collection.',
        );

        return app(OfflinePaymentConfirmationService::class)->confirm(
            orderId: (int) $order->id,
            actorId: (int) $actor->id,
            actorName: $actor->User_Name ?? $actor->name ?? 'Admin',
            actorRole: method_exists($actor, 'getRoleNames')
                ? $actor->getRoleNames()->first()
                : null,
            note: $note,
            transferReference: null,
            signature: [],
        );
    }

    private function ensureCapturedAmwalRefundIsHandledExternally(OrdersPlaced $order): void
    {
        $orderHasCapturedCardStatus = strtolower((string) ($order->Payment_Method ?? '')) === 'card'
            && in_array(
                strtolower((string) ($order->Payment_Status ?? '')),
                ['paid', 'paid_requires_review'],
                true,
            );
        $saleHeaderIds = SalesTransactionHeader::where('Orders_Placed_Id', $order->id)->pluck('id');
        $hasCapturedAmwalPayment = $saleHeaderIds->isNotEmpty()
            ? SalesTransactionDetails::whereIn('Sales_Transaction_Header_Id', $saleHeaderIds)
                ->where('Payment_Gateway', 'amwal_smartbox')
                ->whereIn('Payment_Status', ['paid', 'paid_requires_review'])
                ->exists()
            : false;

        $hasCapturedAmwalAttempt = Schema::hasTable('Payment_Gateway_Attempts_T')
            && DB::table('Payment_Gateway_Attempts_T')
                ->where('Orders_Placed_Id', $order->id)
                ->where('Gateway', 'amwal_smartbox')
                ->whereIn('Status', ['paid', 'paid_requires_review'])
                ->exists();

        abort_if(
            $orderHasCapturedCardStatus || $hasCapturedAmwalPayment || $hasCapturedAmwalAttempt,
            409,
            'Refund or void this captured card transaction in its payment gateway and record reconciliation before cancelling the order.',
        );
    }

    private function isAmwalOrder(OrdersPlaced $order): bool
    {
        $saleHeaderIds = SalesTransactionHeader::where('Orders_Placed_Id', $order->id)->pluck('id');
        $hasAmwalPayment = $saleHeaderIds->isNotEmpty()
            ? SalesTransactionDetails::whereIn('Sales_Transaction_Header_Id', $saleHeaderIds)
                ->where('Payment_Gateway', 'amwal_smartbox')
                ->exists()
            : false;
        $hasAmwalAttempt = Schema::hasTable('Payment_Gateway_Attempts_T')
            && DB::table('Payment_Gateway_Attempts_T')
                ->where('Orders_Placed_Id', $order->id)
                ->where('Gateway', 'amwal_smartbox')
                ->exists();

        return $hasAmwalPayment || $hasAmwalAttempt;
    }

    private function ensureAmwalReturnIsHandledThroughGateway(OrdersPlaced $order): void
    {
        $saleHeaderIds = SalesTransactionHeader::where('Orders_Placed_Id', $order->id)->pluck('id');
        $hasAmwalPayment = $saleHeaderIds->isNotEmpty()
            ? SalesTransactionDetails::whereIn('Sales_Transaction_Header_Id', $saleHeaderIds)
                ->where('Payment_Gateway', 'amwal_smartbox')
                ->exists()
            : false;
        $hasAmwalAttempt = Schema::hasTable('Payment_Gateway_Attempts_T')
            && DB::table('Payment_Gateway_Attempts_T')
                ->where('Orders_Placed_Id', $order->id)
                ->where('Gateway', 'amwal_smartbox')
                ->exists();

        abort_if(
            $hasAmwalPayment || $hasAmwalAttempt,
            409,
            'AmwalPay returns and refunds require a verified APG refund or void and an audited reconciliation before local adjustments.',
        );
    }

    private function notifyCustomerOrderStatus(?OrdersPlaced $order, string $status): void
    {
        if (!$order) {
            return;
        }

        try {
            app(CustomerNotificationService::class)->notifyOrderStatus($order, $status);
        } catch (\Throwable $exception) {
            Log::error('Failed to create customer order status notification', [
                'order_id' => $order->id,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function notifyCustomerReturnRefund(?OrdersPlaced $order, array $result): void
    {
        if (!$order) {
            return;
        }

        $returnedQuantity = collect($result['adjustments'] ?? [])->sum(fn ($row) => (int) ($row['quantity'] ?? 0));
        $refundedAmount = collect($result['adjustments'] ?? [])->sum(fn ($row) => (float) ($row['refund_amount'] ?? 0));

        try {
            app(CustomerNotificationService::class)->notifyReturnRefund($order, (int) $returnedQuantity, $refundedAmount);
        } catch (\Throwable $exception) {
            Log::error('Failed to create customer return/refund notification', [
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
