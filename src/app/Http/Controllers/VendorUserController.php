<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\SecxVendorUser;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\StoreVendorUserRequest;
use App\Http\Requests\UpdateVendorUserRequest;

class VendorUserController extends Controller
{

    public function index(Request $request)
    {
        $q = SecxVendorUser::query()->with('vendor');

        if ($request->filled('vendor_id')) {
            $q->where('Vendor_Id', $request->vendor_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('User_Id', 'like', "%$s%")
                    ->orWhere('User_Name', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%");
            });
        }

        $perPage = (int)($request->per_page ?? 10);

        return $q->orderByDesc('id')->paginate($perPage);
    }

    public function store(StoreVendorUserRequest $request)
    {
        $data = $request->validated();


        $code = CodeGenerator::createCode('VENDUSR', 'Secx_Vendors_Users_Master_T', 'User_Id');

        $user = SecxVendorUser::create([
            'Vendor_Id' => $data['Vendor_Id'],
            'User_Id'   => $code, // will be replaced after insert
            'User_Name' => $data['User_Name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'Phone'     => $data['Phone'] ?? null,
            'Gsm'       => $data['Gsm'] ?? null,
            'Company_Code' => $data['Company_Code'] ?? null,
            'Merchant_Id'  => $data['Merchant_Id'] ?? null,
            'Status'       => $data['Status'] ?? 'active',
            'Password_Changed_Date' => now(),
        ]);



        return response()->json([
            'message' => 'Vendor user created successfully.',
            'data' => $user->load('vendor'),
        ], 201);
    }


    public function update(UpdateVendorUserRequest $request, $id)
    {
        $user = SecxVendorUser::with('vendor')->findOrFail($id);

        $data = $request->validated();

        // handle optional password change
        if (!empty($data['password'] ?? null)) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Vendor user updated successfully.',
            'data'    => $user->fresh()->load('vendor'),
        ]);
    }


    public function resetPassword(ResetVendorUserPasswordRequest $request, $id)
    {
        $user = SecxVendorUser::findOrFail($id);

        $user->password = Hash::make($request->password);
        $user->Password_Changed_Date = now();
        $user->save();

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    public function destroy($id)
    {
        $user = SecxVendorUser::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'Vendor user deleted successfully.',
        ]);
    }
}
