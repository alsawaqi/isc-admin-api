<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\OrdersPlaced;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\OrderProcessLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Models\OrderPackageDetails;
use App\Models\OrdersPlacedDetails;
use Illuminate\Support\Facades\Auth;
use App\Models\SalesTransactionHeader;
use App\Models\SalesTransactionDetails;
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


        $query->with(['customerContact', 'shipper']);

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

        $query->with(['customerContact', 'shipper']);

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


        $query->with(['customerContact', 'shipper']);

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


        $query->with(['customerContact', 'shipper']);

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

        $query->where('Status', 'processing');

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


        $query->with(['customerContact', 'shipper']);

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


        $query->with(['customerContact', 'shipper']);

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


        if (!empty($status)) {
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
            'orderlist',
            'orderlist.product',
            'transaction',
            'transaction.details'
        ])->findOrFail($id);
        return response()->json($order);
    }


    public function packing(Request $request, $id)
    {
        $order        = OrdersPlaced::findOrFail($id);
        $orderDetails = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();
        $saleHeader   = SalesTransactionHeader::where('Orders_Placed_Id', $order->id)->firstOrFail();

        return DB::transaction(function () use ($request, $order, $orderDetails, $saleHeader) {
            try {
                // optional validation (good to keep)
                $request->validate([
                    'signature' => ['required', 'file', 'image', 'max:5120'],
                    'note'      => ['nullable', 'string', 'max:2000'],
                ]);

                // 1) Mark all sales details as "confirmed"
                SalesTransactionDetails::where('Sales_Transaction_Header_Id', $saleHeader->id)
                    ->update(['Payment_Status' => 'confirmed']);

                // 2) Get signature file
                if (! $request->hasFile('signature')) {
                    throw new \RuntimeException('No signature file received.');
                }

                $file = $request->file('signature');

                if (! $file || ! $file->isValid()) {
                    throw new \RuntimeException('Invalid signature upload.');
                }

                // folder in bucket: signatures/orders/{orderId}
                $dir  = "signatures/orders/{$order->id}";

                // returns something like "signatures/orders/100007/abcd1234.png"
                $path = Storage::disk('r2')->put($dir, $file, 'public');

                $imagePath = $path;
                $publicUrl = config('filesystems.disks.r2.url') . '/' . $path;
                $imageMime = $file->getMimeType() ?? 'image/png';

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
                    'Signature_Url'    => $publicUrl,   // or $imagePath if you prefer
                    'Signature_Mime'   => $imageMime,
                    'Notes'            => $request->input('note') ?: null,
                ]);

                return response()->json([
                    'message'       => 'Packing confirmed.',
                    'order_id'      => $order->id,
                    'signature_url' => $publicUrl,
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

        // Normalize selected_lines
        $selectedLineIds = collect($request->input('selected_lines', []))
            ->filter()     // remove null/empty
            ->unique()     // avoid duplicates
            ->values();

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

            $file = $request->file('signature');

            if (! $file || ! $file->isValid()) {
                throw new \RuntimeException('Invalid signature upload.');
            }

            // folder in bucket: signatures/orders/{orderId}
            $dir  = "signatures/orders/{$order->id}";

            // returns something like "signatures/orders/100007/abcd1234.png"
            $path = Storage::disk('r2')->put($dir, $file, 'public');

            $imagePath = $path;
            $publicUrl = config('filesystems.disks.r2.url') . '/' . $path;
            $imageMime = $file->getMimeType() ?? 'image/png';

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
                    'Orders_Placed_Details_Cancelled_Id'   => $cancelledDetail->id,
                    'Step_Code'                            => 'CANCELLED',
                    'Status'                               => 'Cancelled',
                    'Is_External'                          => 0,
                    'Actor_User_Id'                        => Auth::id(),
                    'Actor_Name'                           => Auth::user()?->name ?? 'System',
                    'Actor_Role'                           => 'accounting',
                    'Signed_At'                            => now(),

                    'Signature_Url'    => $publicUrl,   // or $imagePath if you prefer
                    'Signature_Mime'   => $imageMime,
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

        return response()->json(['ok' => true]);
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


            // 2) 🔹 Signature to R2 (simple style)
            $sigFile = $request->file('signature');

            if (! $sigFile || ! $sigFile->isValid()) {
                throw new \RuntimeException('Invalid signature upload.');
            }

            // folder in bucket: signatures/orders/{orderId}
            $dir  = "signatures/orders/{$order->id}";

            // ex: "signatures/orders/100007/abc123.png"
            $path = Storage::disk('r2')->put($dir, $sigFile, 'public');

            $publicUrl = rtrim(config('filesystems.disks.r2.url'), '/') . '/' . ltrim($path, '/');
            $mime      = $sigFile->getMimeType() ?? 'image/png';

            // 3) Log in OrderProcessLog
            OrderProcessLog::create([
                'Orders_Placed_Id'  => $order->id,
                'Step_Code'         => 'DISPATCH_READY',
                'Status'            => 'dispatched',
                'Actor_User_Id'     => Auth::id(),
                'Actor_Name'        => Auth::user()->User_Name ?? 'System',
                'Actor_Role'        => optional(Auth::user())->role,
                'Is_External'       => false,

                'Notes'             => $request->input('note') ?: null,
                'Signature_Url'     => $publicUrl,    // 👈 this is what you wanted
                'Signature_Mime'    => $mime,
                'Signed_At'         => now(),
            ]);

            // 4) Flip status → processing
            $order->update(['Status' => 'processing']);
            foreach ($orderplacedetail as $detail) {
                $detail->update(['Status' => 'processing']);
            }

            return response()->json([
                'message'        => 'Dispatched (processing) recorded.',
                'order_id'       => $order->id,
                'signature_url'  => $publicUrl,
                'evidence_count' => count($evidence),
            ]);
        });
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


            return DB::transaction(function () use ($request, $order, $orderDetails) {



                // 2) Get signature file
                if (! $request->hasFile('signature')) {
                    throw new \RuntimeException('No signature file received.');
                }

                $file = $request->file('signature');

                if (! $file || ! $file->isValid()) {
                    throw new \RuntimeException('Invalid signature upload.');
                }

                // folder in bucket: signatures/orders/{orderId}
                $dir  = "signatures/orders/{$order->id}";

                // returns something like "signatures/orders/100007/abcd1234.png"
                $path = Storage::disk('r2')->put($dir, $file, 'public');

                $imagePath = $path;
                $publicUrl = config('filesystems.disks.r2.url') . '/' . $path;
                $imageMime = $file->getMimeType() ?? 'image/png';




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
                    'Signature_Url'    => $publicUrl,   // e.g. "signatures/orders/123/file.png"

                    'Signature_Mime'   => $imageMime,
                    'Notes'            => $request->input('note')  ?: null,
                ]);

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
            'orderlist.product',                // product fields for each detail
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
                'Shipping_Weight_Kg',
                'Shipping_Price',
                'Tax',
                'Total_Price'
            ]),
            'customer_contact'  => $order->customerContact,
            'shipper'           => $order->shipper,
            'details'           => $order->orderlist,          // with product
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
        });
    }


    public function putOnHold($id)
    {
        $order = OrdersPlaced::where('id', $id)
            ->firstOrFail();

        $order->update(['Status' => 'on-hold']);
    }


    public function removeOnHold($id)
    {


        $order = OrdersPlaced::where('id', $id)
            ->firstOrFail();

        $order->update(['Status' => 'pending']);
    }
}
