<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LoyalityPoints;
use Illuminate\Support\Facades\Auth;
use App\Models\LoyalityPointsHistory;

class LoyalityPointsController extends Controller
{
    public function index(Request $request)
    {
        $loyality = LoyalityPoints::first();


        $loyalityhistory =  LoyalityPointsHistory::orderBy('id', 'desc')->get();

        return response()->json(
            [
                'loyality' => $loyality->Point,
                'loyalityhistory' => $loyalityhistory
            ]
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'points' => 'required|integer|min:0',
        ]);

        try {

            $loyality = LoyalityPoints::first();
            $oldPoints = $loyality->Point;
            $loyality->Point = $request->input('points');
            $loyality->save();

            // Log the change in history
            LoyalityPointsHistory::create([
                'Current_Point' => $loyality->Point,
                'Previous_Point' => $oldPoints,
                'Created_By' =>  Auth::id(),
            ]);



            return response()->json(['message' => 'Loyality points updated successfully.']);
        } catch (\Exception $e) {
            return response()->json(['error' =>  $e->getMessage()], 500);
        }
    }
}
