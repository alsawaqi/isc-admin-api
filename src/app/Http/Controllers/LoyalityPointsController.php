<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LoyalityPoints;
use Illuminate\Support\Facades\Auth;
use App\Models\LoyalityPointsHistory;

class LoyalityPointsController extends Controller
{
    private function settings(): LoyalityPoints
    {
        $loyality = LoyalityPoints::first();

        if ($loyality) {
            return $loyality;
        }

        return LoyalityPoints::create([
            'Point' => 0,
            'Earn_Amount' => 1,
            'Earn_Points' => 0,
            'Redeem_Points' => 1000,
            'Redeem_Amount' => 1,
        ]);
    }

    public function index(Request $request)
    {
        $loyality = $this->settings();
        $earnAmount = (float) ($loyality->Earn_Amount ?? 1);
        $earnPoints = (float) ($loyality->Earn_Points ?? $loyality->Point ?? 0);
        $redeemPoints = (float) ($loyality->Redeem_Points ?? 1000);
        $redeemAmount = (float) ($loyality->Redeem_Amount ?? 1);
        $pointsPerOmaniRial = $earnAmount > 0 ? round($earnPoints / $earnAmount, 3) : (float) ($loyality->Point ?? 0);
        $redemptionValuePerPoint = $redeemPoints > 0 ? round($redeemAmount / $redeemPoints, 6) : 0;

        $loyalityhistory = LoyalityPointsHistory::orderBy('id', 'desc')->get();

        return response()->json(
            [
                'loyality' => $pointsPerOmaniRial,
                'loyality_settings' => [
                    'earn_amount' => $earnAmount,
                    'earn_points' => $earnPoints,
                    'points_per_omr' => $pointsPerOmaniRial,
                    'redeem_points' => $redeemPoints,
                    'redeem_amount' => $redeemAmount,
                    'redemption_value_per_point' => $redemptionValuePerPoint,
                ],
                'loyalityhistory' => $loyalityhistory
            ]
        );
    }

    public function store(Request $request)
    {
        $usesDetailedRules = $request->hasAny([
            'earn_amount',
            'earn_points',
            'redeem_points',
            'redeem_amount',
        ]);

        $request->validate($usesDetailedRules ? [
            'earn_amount' => 'required|numeric|gt:0',
            'earn_points' => 'required|numeric|min:0',
            'redeem_points' => 'required|numeric|gt:0',
            'redeem_amount' => 'required|numeric|gt:0',
        ] : [
            'points' => 'required|numeric|min:0',
        ]);

        try {
            $loyality = $this->settings();
            $oldPoints = $loyality->Point;
            $oldEarnAmount = $loyality->Earn_Amount;
            $oldEarnPoints = $loyality->Earn_Points;
            $oldRedeemPoints = $loyality->Redeem_Points;
            $oldRedeemAmount = $loyality->Redeem_Amount;

            $earnAmount = (float) $request->input('earn_amount', $loyality->Earn_Amount ?? 1);
            $earnPoints = (float) $request->input('earn_points', $request->input('points', $loyality->Earn_Points ?? $loyality->Point ?? 0));
            $redeemPoints = (float) $request->input('redeem_points', $loyality->Redeem_Points ?? 1000);
            $redeemAmount = (float) $request->input('redeem_amount', $loyality->Redeem_Amount ?? 1);
            $pointsPerOmaniRial = $earnAmount > 0 ? round($earnPoints / $earnAmount, 3) : 0;

            $loyality->Point = $pointsPerOmaniRial;
            $loyality->Earn_Amount = $earnAmount;
            $loyality->Earn_Points = $earnPoints;
            $loyality->Redeem_Points = $redeemPoints;
            $loyality->Redeem_Amount = $redeemAmount;
            $loyality->save();

            LoyalityPointsHistory::create([
                'Current_Point' => $loyality->Point,
                'Previous_Point' => $oldPoints,
                'Current_Earn_Amount' => $loyality->Earn_Amount,
                'Previous_Earn_Amount' => $oldEarnAmount,
                'Current_Earn_Points' => $loyality->Earn_Points,
                'Previous_Earn_Points' => $oldEarnPoints,
                'Current_Redeem_Points' => $loyality->Redeem_Points,
                'Previous_Redeem_Points' => $oldRedeemPoints,
                'Current_Redeem_Amount' => $loyality->Redeem_Amount,
                'Previous_Redeem_Amount' => $oldRedeemAmount,
                'Created_By' =>  Auth::id(),
            ]);



            return response()->json(['message' => 'Loyalty points updated successfully.']);
        } catch (\Exception $e) {
            return response()->json(['error' =>  $e->getMessage()], 500);
        }
    }
}
