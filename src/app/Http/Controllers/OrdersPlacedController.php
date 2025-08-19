<?php

namespace App\Http\Controllers;

use App\Models\OrdersPlaced;
use Illuminate\Http\Request;

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
        return response()->json(OrdersPlaced::where('Status', 'delivered')->with(['customerContact','shipper'])->orderBy('id','desc')->get());
    }



    public function show($id)
    {
        $order = OrdersPlaced::with(['customerContact','shipper','orderlist','orderlist.product'])->findOrFail($id);
        return response()->json($order);
    }


    public function packing($id)
     {
            $order = OrdersPlaced::where('id', $id)
                                    ->firstOrFail();
            $order->update(['Status' => 'packed']);
      }

      public function dispatch($id)
      {
            $order = OrdersPlaced::where('id', $id)
                                    ->firstOrFail();
            $order->update(['Status' => 'processing']);
      }

      public function shipment($id)
      {
            $order = OrdersPlaced::where('id', $id)
                                    ->firstOrFail();
            $order->update(['Status' => 'shipped']);
      }

      public function complete($id)
      {
            $order = OrdersPlaced::where('id', $id)
                                    ->firstOrFail();
            $order->update(['Status' => 'delivered']);
      }
}
