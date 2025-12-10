<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\District;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Geo\StoreDistrictRequest;
use App\Http\Requests\Geo\UpdateDistrictRequest;

class DistrictController extends Controller
{
    public function index(Request $request)
    {

        try {


            $q = District::query()
                ->select('id', 'Region_Id', 'District_Code', 'District_Name', 'District_Name_Ar', 'Created_By', 'created_at')
                ->with(['region:id,Region_Name,Country_Id', 'region.country']);

            if ($rid = $request->integer('region_id')) {
                $q->where('Geox_District_Master_T.Region_Id', $rid);
            }
            if ($cid = $request->integer('country_id')) {
                $q->whereHas('region', fn($qq) => $qq->where('Country_Id', $cid));
            }
            if ($s = $request->string('search')->toString()) {
                $q->where(function ($qq) use ($s) {
                    $qq->where('District_Name', 'like', "%$s%")
                        ->orWhere('District_Code', 'like', "%$s%");
                });
            }

            return response()->json($q->orderBy('District_Name')->paginate($request->integer('per_page', 20)));
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error fetching districts: ' . $e->getMessage()], 500);
        }
    }

    public function byRegion(int $regionId)
    {
        return  response()->json(District::query()
            ->select('id', 'Region_Id', 'District_Code', 'District_Name', 'District_Name_Ar')
            ->where('Region_Id', $regionId)
            ->orderBy('District_Name')
            ->get());
    }

    public function show(District $district)
    {
        $district->load(['region:id,Region_Name,Country_Id']);
        return $district;
    }

    public function store(Request $request)
    {
        try {

            $District_Code = CodeGenerator::createCode('DISTRICT', 'Geox_District_Master_T', 'District_Code');

            $district = District::create([
                'Region_Id'      => $request->input('Region_Id'),
                'District_Code'  => $District_Code,
                'District_Name'  => $request->input('District_Name'),
                'District_Name_Ar' => $request->input('District_Name_Ar'),
                'Created_By'    =>  Auth::id(),
            ]);


            $district->load(['region:id,Region_Name,Country_Id']);
            return response()->json($district, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error creating district: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, District $district)
    {
        $district->update($request->all());
        $district->load(['region:id,Region_Name,Country_Id']);
        return $district;
    }

    public function destroy(District $district)
    {
        $district->delete();
        return response()->noContent();
    }
}
