<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;

class CountryController extends Controller
{
    // app/Http/Controllers/CountryController.php
    public function index()
    {
        return response()->json(Country::latest()->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'Country_Name' => 'required|string|max:255',
        ]);


        $countryCode = CodeGenerator::createCode('COUNTRY', 'Geox_Country_Master_T', 'Country_Code');

        $country = Country::create([
            'Country_Code' => $countryCode,
            'Country_Name' => $request->Country_Name,
            'Country_Name_Ar' => $request->Country_Name_Ar,
            'Created_By' =>  Auth::id() ?? null,
            'Created_Date' => now(),
        ]);

        return response()->json([
            'message' => 'Country created successfully.',
            'country' => $country
        ], 201);
    }


    public function update(Request $request, Country $country)
    {


        $country->Country_Name = $request->Country_Name;
        $country->Country_Name_Ar = $request->Country_Name_Ar;
        $country->save();

        return response()->json([
            'message' => 'Country updated successfully.',
            'country' => $country
        ], 200);
    }


    public function destroy(Country $country)
    {
        $country->delete();

        return response()->json([
            'message' => 'Country deleted successfully.'
        ], 200);
    }
}
