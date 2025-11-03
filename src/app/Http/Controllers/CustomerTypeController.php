<?php

namespace App\Http\Controllers;

use App\Models\CustomerType;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;

class CustomerTypeController extends Controller
{
    //
    public function index()
    {
        return response()->json(
            CustomerType::orderBy('id', 'DESC')->get()
        );
    }

    public function store(Request $request)
    {


          $Customer_Type_Code = CodeGenerator::createCode('CUSTTYPE', 'Customers_Types_Master_T', 'Customer_Type_Code');

        $customerType = CustomerType::create([
            'Customer_Type_Code' => $Customer_Type_Code,
            'Customer_Type_Name' => $request->input('Customer_Type_Name'),
            'Customer_Type_Description' => $request->input('Customer_Type_Description'),
        ]);

        return response()->json($customerType, 201);
    }
    
}
