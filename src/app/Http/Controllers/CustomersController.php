<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use Illuminate\Http\Request;

class CustomersController extends Controller
{
    //


    public function index()
    {

        return response()->json(Customers::all());


    }

}
