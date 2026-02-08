<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use App\Models\VendorMaster;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\VendorStoreRequest;
use App\Http\Requests\VendorUpdateRequest;

class VendorController extends Controller
{

    public function all()
    {
        return VendorMaster::query()
            ->select('id', 'Vendor_Code', 'Vendor_Name')
            ->orderBy('Vendor_Name')
            ->get();
    }


  public function index(Request $request)
  {
    $perPage = (int) ($request->input('per_page', 10));
    $search  = trim((string) $request->input('search', ''));
    $sortBy  = (string) $request->input('sort_by', 'Id');
    $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

    // allowlist to prevent SQL injection via sort_by
    $allowedSort = ['Id','Vendor_Code','Vendor_Name','Email_1','Phone_No','Status','Is_Active','created_at'];
    if (!in_array($sortBy, $allowedSort, true)) $sortBy = 'Id';

    $q = VendorMaster::query();

    if ($search !== '') {
      $q->where(function ($qq) use ($search) {
        $qq->where('Vendor_Name', 'like', "%{$search}%")
          ->orWhere('Vendor_Code', 'like', "%{$search}%")
          ->orWhere('Email_1', 'like', "%{$search}%")
          ->orWhere('Phone_No', 'like', "%{$search}%")
          ->orWhere('CR_Number', 'like', "%{$search}%");
      });
    }

    $q->orderBy($sortBy, $sortDir);

    return $q->paginate($perPage);
  }

  public function store(VendorStoreRequest $request)
  {
    $code = CodeGenerator::createCode('VENDOR', 'Vendors_Master_T', 'Vendor_Code');



    $payload = $request->validated();
    $payload['Vendor_Code'] = $code;

    $vendor = VendorMaster::create($payload);

    return response()->json([
      'message' => 'Vendor created successfully.',
      'data' => $vendor,
    ], 201);
  }

  public function update(VendorUpdateRequest $request, $id)
  {
    $vendor = VendorMaster::where('Id', $id)->firstOrFail();

    $vendor->update($request->validated());

    return response()->json([
      'message' => 'Vendor updated successfully.',
      'data' => $vendor,
    ]);
  }

  public function destroy($id)
  {
    $vendor = VendorMaster::where('Id', $id)->firstOrFail();
    $vendor->delete();

    return response()->json(['message' => 'Vendor deleted successfully.']);
  }
}
