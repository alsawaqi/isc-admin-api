<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShipperWeightRateRequest;
use App\Http\Requests\UpdateShipperWeightRateRequest;
use App\Models\ShipperDestination;
use App\Models\ShipperWeightRate;
use Illuminate\Http\Request;

class ShipperWeightRateController extends Controller
{
    // List all weight rates for a destination
    public function index(Request $request, ShipperDestination $destination)
    {
        $q = ShipperWeightRate::query()
            ->where('Shippers_Destination_Id', $destination->id)
            ->select('id','Shippers_Id','Shippers_Destination_Id',
                'Shippers_Standard_Shipping_Weight_Size','Shippers_Standard_Shipping_Weight_Rate',
                'Shippers_Currency','Shippers_Min_Weight_Kg','Shippers_Max_Weight_Kg',
                'Shippers_Base_Fee','Shippers_Per_Kg_Fee','Shippers_Flat_Fee','created_at');

        // Optional filters
        if ($min = $request->input('min_kg')) $q->where('Shippers_Min_Weight_Kg','<=',$min);
        if ($max = $request->input('max_kg')) $q->where('Shippers_Max_Weight_Kg','>=',$max);

        return $q->orderBy('Shippers_Min_Weight_Kg')->paginate($request->integer('per_page', 20));
    }

    // Create a weight rate for a destination
    public function store(StoreShipperWeightRateRequest $request, ShipperDestination $destination)
    {
        $payload = $request->validated();
        $payload['Shippers_Id'] = $destination->Shippers_Id;
        $payload['Shippers_Destination_Id'] = $destination->id;

        // Optional: prevent overlapping KG bands for same destination
        if (!empty($payload['Shippers_Min_Weight_Kg']) || !empty($payload['Shippers_Max_Weight_Kg'])) {
            $overlap = ShipperWeightRate::where('Shippers_Destination_Id', $destination->id)
                ->where(function($q) use ($payload) {
                    $min = $payload['Shippers_Min_Weight_Kg'] ?? 0;
                    $max = $payload['Shippers_Max_Weight_Kg'] ?? PHP_INT_MAX;
                    $q->where(function($qq) use($min,$max){
                        $qq->where('Shippers_Min_Weight_Kg','<=',$max)
                           ->where('Shippers_Max_Weight_Kg','>=',$min);
                    });
                })->exists();
            if ($overlap) return response()->json(['message'=>'Overlapping weight band for this destination.'], 422);
        }

        $rate = ShipperWeightRate::create($payload);
        return response()->json($rate, 201);
    }

    // Update a weight rate
    public function update(UpdateShipperWeightRateRequest $request, ShipperWeightRate $rate)
    {
        $rate->update($request->validated());
        return $rate;
    }

    public function destroy(ShipperWeightRate $rate)
    {
        $rate->delete();
        return response()->noContent();
    }
}