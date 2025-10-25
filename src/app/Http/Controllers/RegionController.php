<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;

class RegionController extends Controller
{
    public function countries_index()
    {
        return response()->json(
            Country::latest()->get()
        );
    }

    public function index()
    {
        $region = Region::with('country')->latest()->get();
        return response()->json($region);
    }



    public function byCountry(int $countryId)
    {
        return response()->json(Region::query()
            ->select('id', 'Country_Id', 'Region_Name', 'Region_Name_Ar', 'Region_Code')
            ->where('Country_Id', $countryId)
            ->orderBy('Region_Name')
            ->get());
    }


    public function store(Request $request)
    {
        $request->validate([

            'Region_Name' => 'required|string|max:255',

        ]);

        $RegionCode = CodeGenerator::createCode('REGION', 'Geox_Region_Master_T', 'Region_Code');

        $region = Region::create([
            'Region_Code' => $RegionCode,
            'Country_Id' => $request->Country_Id,
            'Region_Name' => $request->Region_Name,
            'Region_Name_Ar' => $request->Region_Name_Ar,
            'Created_By' => Auth::id() ?? null,
            'Created_Date' => now(),
        ]);

        return response()->json(['message' => 'Region created successfully', 'region' => $region], 201);
    }


    public function update(Request $request, Region $region)
    {
        $region->Country_Id = $request->Country_Id;
        $region->Region_Name = $request->Region_Name;
        $region->Region_Name_Ar = $request->Region_Name_Ar;
        $region->save();

        return response()->json(['message' => 'Region updated successfully', 'region' => $region]);
    }
}
