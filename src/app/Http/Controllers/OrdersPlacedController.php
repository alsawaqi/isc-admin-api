<?php

namespace App\Http\Controllers;

use App\Models\OrdersPlaced;
use Illuminate\Http\Request;

class OrdersPlacedController extends Controller
{
    //


    public function index()
    {
        return response()->json(OrdersPlaced::with('customerContact')->orderBy('id','desc')->get());
    }
}
