<?php

namespace App\Http\Controllers;

use App\Models\CustomerType;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Nette\Utils\Json;
use Symfony\Component\HttpFoundation\JsonResponse;

class CustomerTypeController extends Controller
{
    //
    public function index(Request $request) : JsonResponse
    { 

        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);


         $query = CustomerType::query();


           
        if ($search) {
            $query->where('Customer_Type_Name', 'like', "%{$search}%");
        }



            // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Customer_Type_Name', 'created_at'])) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortDir);
        return response()->json(
            $query->paginate($perPage)
        );

 

       
    }

    public function store(Request $request)
    {


          $Customer_Type_Code = CodeGenerator::createCode('CUSTTYPE', 'Customers_Types_Master_T', 'Customer_Type_Code');

        $customerType = CustomerType::create([
            'Customer_Type_Code' => $Customer_Type_Code,
            'Customer_Type_Name' => $request->input('Customer_Type_Name'),
            'Customer_Type_Name_Ar' => $request->input('Customer_Type_Name_Ar'),
            'Customer_Type_Description' => $request->input('Customer_Type_Description'),
        ]);

        return response()->json($customerType, 201);
    }


    public function update(Request $request, CustomerType $customertype)
    {
        $customertype->update([
            'Customer_Type_Name' => $request->input('Customer_Type_Name'),
            'Customer_Type_Name_Ar' => $request->input('Customer_Type_Name_Ar'),
            'Customer_Type_Description' => $request->input('Customer_Type_Description'),
        ]);

        return response()->json($customertype);
    }


    public function destroy(CustomerType $customertype)
    {
        $customertype->delete();

        return response()->json(null, 204);
    }
    
}
