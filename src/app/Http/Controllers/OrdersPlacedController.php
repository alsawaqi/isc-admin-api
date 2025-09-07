<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use App\Models\OrdersPlaced;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\OrderProcessLog;
use Illuminate\Support\Facades\DB;
use App\Models\OrderPackageDetails;
use App\Models\OrdersPlacedDetails;
use Illuminate\Support\Facades\Auth;
use App\Models\SalesTransactionHeader;
use App\Models\SalesTransactionDetails;
use Illuminate\Support\Facades\Storage;

class OrdersPlacedController extends Controller
{
    //


    public function index()
    {
        return response()->json(OrdersPlaced::where('Status', 'pending')->with(['customerContact','shipper'])->orderBy('id','desc')->get());
    }


    public function packing_index(){

        return response()->json(OrdersPlaced::where('Status', 'packed')->with(['customerContact','shipper'])->orderBy('id','desc')->get());
    }


    public function dispatch_index(){

        return response()->json(OrdersPlaced::where('Status', 'processing')->with(['customerContact','shipper'])->orderBy('id','desc')->get());
    }


     public function shipment_index(){

        return response()->json(OrdersPlaced::where('Status', 'shipped')->with(['customerContact','shipper'])->orderBy('id','desc')->get());
    }


    public function delivered_index(){
        return response()->json(OrdersPlaced::with(['customerContact','shipper'])->orderBy('id','desc')->get());
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


   public function packing(Request $request, $id){


            $order = OrdersPlaced::findOrFail($id);

            $orderplacedetail = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();

            $saleheader = SalesTransactionHeader::where('Orders_Placed_Id', $order->id)->first();

             $salesdetails = SalesTransactionDetails::where('Sales_Transaction_Header_Id', $saleheader->id)->first();


             DB::transaction(function () use ($request, $order, $orderplacedetail, $salesdetails) {

             $salesdetails->update(['Payment_Status' => 'confirmed']);

              
            // Expect a data URL from the client: "data:image/png;base64,AAAA..."
            $dataUrl = (string) $request->input('signature');
            if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $dataUrl, $m)) {
                return response()->json(['message' => 'Invalid signature data URL'], 422);
            }

            $ext = $m[1] === 'jpg' ? 'jpeg' : $m[1];
            $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));

            if ($binary === false) {
                return response()->json(['message' => 'Could not decode signature'], 422);
            }

            $dir = "signatures/orders/{$order->id}";
            // ensure directory exists under the *public* disk root: storage/app/public
            Storage::disk('public')->makeDirectory($dir);

            $filename = 'signature_'.now()->format('Ymd_His').'_'.Str::random(6).'.'.$ext;
            $path = "$dir/$filename";

            Storage::disk('public')->put($path, $binary, 'public'); // visibility = public
            $publicUrl = Storage::url($path); // => /storage/signatures/orders/ID/filename.png

            // update order status + write process log
            $order->update(['Status' => 'packed']);

              foreach($orderplacedetail as $detail){

                    $detail->update(['Status' => 'packed']);
              }

            OrderProcessLog::create([
                'Orders_Placed_Id' => $order->id,
                'Step_Code'        => 'PACKING_CONFIRMED',
                'Status'           => 'Packed',
                'Is_External'      => false,
                'Actor_User_Id'    => Auth::id(),
                'Actor_Name'       => 'Name',
                'Actor_Role'       => 'accounting', // or whatever applies
                 'Is_External'      => 1,
              
           
                'Signed_At'        => now(),
                'Signature_Storage'=> 'test',        // storage path under public disk
                'Signature_Url'     => 'tttt',    // public URL
                'Signature_Mime'    => '$sigMime' ?: 'image/png',
                'Notes'            => $request->input('note'),
            ]);

       

            return response()->json(['ok' => true, 'url' => $publicUrl]);
             });

    }

       

    public function dispatch(Request $request, $id)
    {
        // keep it minimal: files optional, signature required
        $request->validate([
            'files'     => ['array'],           // files[DETAIL_ID][] (optional)
            'files.*'   => ['array'],
            'files.*.*' => ['file','image','max:5120'],
            'signature' => ['required'],        // file OR data URL
            'note'      => ['nullable','string','max:2000'],
        ]);

        $order = OrdersPlaced::findOrFail($id);
        $orderplacedetail = OrdersPlacedDetails::where('Orders_Placed_Id', $order->id)->get();

        return DB::transaction(function () use ($request, $order, $orderplacedetail) {

            // 1) Save any photos to Orders_Packaging_Details_T (simple loop, no extra rules)
            $evidence = [];
            foreach ((array) $request->file('files', []) as $detailId => $files) {
                foreach ((array) $files as $file) {
                    $dir  = "public/orders/packaging/{$order->id}/{$detailId}";
                    $name = 'packed_'.now()->format('Ymd_His').'_'.Str::random(5).'.'.($file->getClientOriginalExtension() ?: 'jpg');
                    $path = $file->storeAs($dir, $name);

                    $row = OrderPackageDetails::create([
                        'Packaging_Code'           => 'PKG-'.'-'.Str::upper(Str::random(4)),
                        'Orders_Placed_Id'         => $order->id,
                        'Orders_Placed_Details_Id' => (int) $detailId,
                        'Packed_Image'             => Storage::url($path),
                        'Packed_By'                => Auth::id(),
                    ]);

                    $evidence[] = $row->Packed_Image;
                }
            }

            // 2) Signature (file OR data URL → save to public disk)
            [$sigPath, $sigUrl, $sigMime] = $this->saveSignature($order->id, $request->file('signature'), $request->input('signature'));

          OrderProcessLog::create([
                        'Orders_Placed_Id'  => $order->id,
                        'Step_Code'         => 'DISPATCH_READY',
                        'Status'            => 'confirmed',
                        'Actor_User_Id'     => Auth::id(),
                        'Actor_Name'        => Auth::user()->User_Name,
                        'Actor_Role'        => optional(Auth::user())->role, // string or null
                        'Is_External'       => false,
                     
                        'Notes'             => $request->input('note') ?: null,
                        // e.g. "public/signatures/orders/..."
                        'Signature_Url'     => $sigUrl,    // public URL
                        // DO NOT set Signature_Blob at all (let it be NULL)
                        'Signature_Mime'    => $sigMime ?: 'image/png',
                        'Signed_At'         => now(),
                    ]);


            // 3) Flip status → processing
            $order->update(['Status' => 'processing']);

             foreach($orderplacedetail as $detail){

                    $detail->update(['Status' => 'processing']);
              }

            return response()->json([
                'message'        => 'Dispatched (processing) recorded.',
                'order_id'       => $order->id,
                'signature_url'  => $sigUrl,
                'evidence_count' => count($evidence),
            ]);
        });
    }

    /**
     * Minimal helper: accept either uploaded file or data URL string.
     */
    protected function saveSignature(int $orderId, $file = null, ?string $dataUrl = null): array
    {
        $dir = "public/signatures/orders/{$orderId}";
        Storage::makeDirectory($dir);

        if ($file) {
            $ext  = $file->getClientOriginalExtension() ?: 'png';
            $name = 'dispatch_'.now()->format('Ymd_His').'_'.Str::random(5).'.'.$ext;
            $path = $file->storeAs($dir, $name);
            return [$path, Storage::url($path), $file->getClientMimeType()];
        }

        // very small parser for data:image/*;base64,...
        if ($dataUrl && preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUrl, $m)) {
            $mime = $m[1];
            $bin  = base64_decode($m[2]);
            $ext  = str_contains($mime, 'jpeg') ? 'jpg' : explode('/', $mime)[1] ?? 'png';
            $name = 'dispatch_'.now()->format('Ymd_His').'_'.Str::random(5).'.'.$ext;
            $path = "{$dir}/{$name}";
            Storage::put($path, $bin);
            return [$path, Storage::url($path), $mime];
        }

        // If we reach here, signature was required but invalid
        abort(422, 'Invalid signature.');
    }

      public function shipment(Request $request,$id){

            $order = OrdersPlaced::findOrFail($id);

  

                // Expect data URL "data:image/png;base64,..."
                $dataUrl = (string) $request->input('signature');
                if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $dataUrl, $m)) {
                    return response()->json(['message' => 'Invalid signature data URL'], 422);
                }

                $ext    = $m[1] === 'jpg' ? 'jpeg' : $m[1];
                $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));
                if ($binary === false) {
                    return response()->json(['message' => 'Could not decode signature'], 422);
                }

                $dir = "signatures/orders/{$order->id}";
                Storage::disk('public')->makeDirectory($dir);

                $filename = 'signature_'.now()->format('Ymd_His').'_'.Str::random(6).'.'.$ext;
                $path = "$dir/$filename";

                Storage::disk('public')->put($path, $binary, 'public');
                $publicUrl = Storage::url($path); // /storage/...

                // Update status
                $order->update(['Status' => 'shipped']);

                // Log process (no varbinary writes)
                OrderProcessLog::create([
                    'Orders_Placed_Id'  => $order->id,
                    'Step_Code'         => 'PACKING_CONFIRMED',
                    'Status'            => 'done',
                    'Actor_User_Id'     => Auth::id(),
                    'Actor_Name'        => optional(Auth::user())->User_Name,
                    'Actor_Role'        => optional(Auth::user())->role, // adjust if different
                    'Is_External'       => false,
                       // storage path under public disk
                    'Signature_Url'     => $publicUrl,   // public URL
                    'Signature_Mime'    => "image/{$ext}",
                    'Signed_At'         => now(),
                    'Notes'             => $request->input('note'),
                ]);

                return response()->json(['ok' => true, 'url' => $publicUrl]);



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
                'id','Order_Code','Transaction_Number','Status','created_at',
                'Shipping_Weight_Kg','Shipping_Price','Tax','Total_Price'
            ]),
            'customer_contact'  => $order->customerContact,
            'shipper'           => $order->shipper,
            'details'           => $order->orderlist,          // with product
            'transaction'       => $order->transaction,        // with details
            'packages_by_detail'=> $packagesByDetail,          // grouped
            'logs'              => $logs,                      // timeline
        ]);
    }

    private function decodeEvidence($json)
    {
        if (!$json) return [];
        try { return json_decode($json, true) ?: []; } catch (\Throwable $e) { return []; }
    }


      public function complete($id)
      {
            $order = OrdersPlaced::where('id', $id)
                                    ->firstOrFail();
            $order->update(['Status' => 'delivered']);
      }
}
