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

  public function index()
{
    $cities = City::with(['state.country'])->latest()->get();
    return response()->json($cities);
}

public function store(Request $request)
{
    $request->validate([
         'City_Name' => 'required|string|max:255',
      
    ]);



    $citycode = CodeGenerator::createCode('COUNTRY', 'Geox_City_Master_T', 'City_Code');


    $city = City::create([
        'City_Code' => $citycode,
        'State_Id' => $request->State_Id,
        'City_Name' => $request->City_Name,
        'City_Name_Ar' => $request->City_Name,
        'Created_By' => Auth::id() ?? null,
        'Created_Date' => now(),
    ]);

    return response()->json(['message' => 'City created successfully', 'city' => $city], 201);
}

}
