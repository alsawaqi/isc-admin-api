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
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Support\Facades\Storage;
use App\Models\OrdersPlacedDetailsCancelled;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

class OrdersPlacedController extends Controller
{
    //


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
            'vendorOrders.vendor',
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
            'vendorOrders',
            'vendorOrders.vendor',
            'transaction',
            'transaction.details'
        ])->findOrFail($id);
        return response()->json($order);
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
        $saleHeader   = SalesTransactionHeader::where('Orders_Placed_Id', $order->id)->firstOrFail();

        return DB::transaction(function () use ($request, $order, $orderDetails, $saleHeader) {
            try {
                // 1) Mark all sales details as "confirmed"
                SalesTransactionDetails::where('Sales_Transaction_Header_Id', $saleHeader->id)
                    ->update(['Payment_Status' => 'confirmed']);

                // 2) Get signature file
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




    public function cancel(Request $request, $id): JsonResponse
    {
        $order = OrdersPlaced::findOrFail($id);

        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'signature' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $selectedLineIds = $this->selectedLineIdsFromRequest($request);

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
            'orderlist.product.vendor',
            'orderlist.product.department',
            'orderlist.product.subDepartment',
            'orderlist.product.subSubDepartment',
            'orderlist.adjustments',
            'vendorOrders.vendor',
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
                'Total_Price'
            ]),
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

        $order = OrdersPlaced::where('id', $id)
            ->firstOrFail();

        $orderplacedetail = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();
        DB::transaction(function () use ($order, $orderplacedetail) {
            foreach ($orderplacedetail as $detail) {

                $detail->update(['Status' => 'delivered']);
            }
            $order->update(['Status' => 'delivered']);

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
        });

        $this->notifyCustomerOrderStatus($order->fresh(), 'delivered');

        return response()->json([
            'message' => 'Order completed.',
            'order_id' => $order->id,
            'status' => 'delivered',
        ]);
    }


    public function pickupComplete($id)
    {
        $order = OrdersPlaced::where('id', $id)
            ->where('Delivery_Type', 'pickup')
            ->firstOrFail();

        $orderplacedetail = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();

        DB::transaction(function () use ($order, $orderplacedetail) {
            foreach ($orderplacedetail as $detail) {
                $detail->update(['Status' => 'delivered']);
            }

            $order->update(['Status' => 'delivered']);

            OrderProcessLog::create([
                'Orders_Placed_Id' => $order->id,
                'Step_Code'        => 'PICKUP_COLLECTED',
                'Status'           => 'delivered',
                'Is_External'      => false,
                'Actor_User_Id'    => Auth::id(),
                'Actor_Name'       => optional(Auth::user())->User_Name ?? 'System',
                'Actor_Role'       => optional(Auth::user())->role ?? null,
                'Notes'            => 'Customer collected pickup order.',
            ]);
        });

        $this->notifyCustomerOrderStatus($order->fresh(), 'delivered');

        return response()->json([
            'message' => 'Pickup order collected.',
            'order_id' => $order->id,
            'status' => 'delivered',
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
