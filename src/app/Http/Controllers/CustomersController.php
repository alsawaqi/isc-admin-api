<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use App\Models\CustomerCart;
use App\Models\UserCustomer;
use Illuminate\Http\Request;
use App\Models\CustomerFavoirtes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomersController extends Controller
{
    //


    public function index(Request $request): JsonResponse
    {

        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = Customers::query();


        $query->with(['loyalty','users']);

        if ($search) {
            $query->where('Customer_Full_Name', 'like', "%{$search}%")

                ->orWhere('Email_Address', 'like', "%{$search}%")

                ->orWhere('Telephone', 'like', "%{$search}%");
        }

        if (!in_array($sortBy, ['id', 'Customer_Full_Name', 'created_at'])) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }


    public function index_carts(Request $request): JsonResponse
    {
        $customerId = $request->customer_id;

        $customer = Customers::find($customerId);

        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = CustomerCart::query();

        $query->with(['product']);


        $query->orderBy($sortBy, $sortDir);

        if (!empty($customerId)) {
            $query->where('Customers_Id', $customerId);
        }


        return response()->json(
            [
                'data' => $query->paginate($perPage),
                'customer' => $customer
            ]
        );
    }


    public function index_favorites(Request $request): JsonResponse
    {
        $customerId = $request->customer_id;

        $customer = Customers::find($customerId);

        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = CustomerFavoirtes::query();

        $query->with(['product']);

        $query->orderBy($sortBy, $sortDir);
        if (!empty($customerId)) {
            $query->where('Customers_Id', $customerId);
                 
        }
        return response()->json(
            [
                'data' => $query->paginate($perPage),
                'customer' => $customer
            ]
        );
    }


     public function block($id)
    {
        $user = UserCustomer::findOrFail($id);

        $user->No_Login = 1;

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User has been blocked',
            'user'    => $user,
        ]);
    }

    public function unblock($id)
    {
        $user = UserCustomer::findOrFail($id);

        $user->No_Login = 0;

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User has been unblocked',
            'user'    => $user,
        ]);
    }
}
