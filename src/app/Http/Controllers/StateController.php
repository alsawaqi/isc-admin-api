<?php

namespace App\Http\Controllers;

use App\Models\State;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;

class StateController extends Controller
{

    public function countries_index()
    {
        return response()->json(

            Country::latest()->get()
        );
    }

    public function index()
    {
        $states = State::with('country')->latest()->get();
        return response()->json($states);
    }

    public function store(Request $request)
    {
        $request->validate([

            'State_Name' => 'required|string|max:255',

        ]);

        $StateCode = CodeGenerator::createCode('COUNTRY', 'Geox_State_Master_T', 'State_Code');

        $state = State::create([
            'State_Code' => $StateCode,
            'Country_Id' => $request->Country_Id,
            'State_Name' => $request->State_Name,
            'State_Name_Ar' => $request->State_Name,
            'Created_By' => Auth::id() ?? null,
            'Created_Date' => now(),
        ]);

        return response()->json(['message' => 'State created successfully', 'state' => $state], 201);
    }
}
