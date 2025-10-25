<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\State;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;

class CityController extends Controller
{

    public function byCountry($countryId)
    {
        return response()->json(State::where('Country_Id', $countryId)->get());
    }

    public function index(Request $request)
    {
        $q = City::query()
            ->select('id', 'District_Id', 'City_Code', 'City_Name', 'City_Name_Ar', 'created_at')
            ->with([
                'district:id,Region_Id,District_Name',
                'district.region:id,Country_Id,Region_Name',
                'district.region.country:id,Country_Name'
            ]);

        if ($cid = $request->integer('country_id')) {
            $q->whereHas('district.region', fn($qq) => $qq->where('Country_Id', $cid));
        }
        if ($rid = $request->integer('region_id')) {
            $q->whereHas('district', fn($qq) => $qq->where('Region_Id', $rid));
        }
        if ($did = $request->integer('district_id')) {
            $q->where('District_Id', $did);
        }
        if ($s = $request->string('search')->toString()) {
            $q->where(fn($qq) => $qq->where('City_Name', 'like', "%$s%")
                ->orWhere('City_Code', 'like', "%$s%"));
        }

        return response()->json($q->orderBy('City_Name')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $request->validate([
            'City_Name' => 'required|string|max:255',

        ]);



        $citycode = CodeGenerator::createCode('COUNTRY', 'Geox_City_Master_T', 'City_Code');


        $city = City::create([
            'City_Code' => $citycode,
            'District_Id' => $request->District_Id,
            'City_Name' => $request->City_Name,
            'City_Name_Ar' => $request->City_Name,
            'Created_By' => Auth::id() ?? null,
            'Created_Date' => now(),
        ]);

        return response()->json(['message' => 'City created successfully', 'city' => $city], 201);
    }

    public function update(Request $request, City $city)
    {
        $city->District_Id = $request->District_Id;
        $city->City_Name = $request->City_Name;
        $city->City_Name_Ar = $request->City_Name_Ar;
        $city->save();

        return response()->json(['message' => 'City updated successfully', 'city' => $city]);
    }
}
