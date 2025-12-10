<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShipperVolumeRateRequest;
use App\Http\Requests\UpdateShipperVolumeRateRequest;
use App\Models\ShipperDestination;
use App\Models\ShipperVolumeRate;
use Illuminate\Http\Request;

class ShipperVolumeRateController extends Controller
{
    // List all volume rates for a destination
    public function index(Request $request, ShipperDestination $destination)
    {
        $q = ShipperVolumeRate::query()
            ->where('Shippers_Destination_Id', $destination->id)
            ->select(
                'id',
                'Shippers_Id',
                'Shippers_Destination_Id',
                'Shippers_Standard_Shipping_Volume_Size',
                'Shippers_Standard_Shipping_Volume_Rate',
                'Shippers_Currency',
                'Shippers_Min_Volume_Cbm',
                'Shippers_Max_Volume_Cbm',
                'Shippers_Base_Fee',
                'Shippers_Per_Cbm_Fee',
                'Shippers_Flat_Fee',
                'created_at'
            );

        // Optional filters
        if ($min = $request->input('min_cbm')) $q->where('Shippers_Min_Volume_Cbm', '<=', $min);
        if ($max = $request->input('max_cbm')) $q->where('Shippers_Max_Volume_Cbm', '>=', $max);

        return $q->orderBy('Shippers_Min_Volume_Cbm')->paginate($request->integer('per_page', 20));
    }

    // Create a volume rate for a destination
    public function store(StoreShipperVolumeRateRequest $request, ShipperDestination $destination)
    {
        $payload = $request->validated();
        $payload['Shippers_Id'] = $destination->Shippers_Id;
        $payload['Shippers_Destination_Id'] = $destination->id;

        // Optional: prevent overlapping CBM bands for same destination
        if (!empty($payload['Shippers_Min_Volume_Cbm']) || !empty($payload['Shippers_Max_Volume_Cbm'])) {
            $overlap = ShipperVolumeRate::where('Shippers_Destination_Id', $destination->id)
                ->where(function ($q) use ($payload) {
                    $min = $payload['Shippers_Min_Volume_Cbm'] ?? 0;
                    $max = $payload['Shippers_Max_Volume_Cbm'] ?? PHP_INT_MAX;
                    $q->where(function ($qq) use ($min, $max) {
                        $qq->where('Shippers_Min_Volume_Cbm', '<=', $max)
                            ->where('Shippers_Max_Volume_Cbm', '>=', $min);
                    });
                })->exists();
            if ($overlap) return response()->json(['message' => 'Overlapping volume band for this destination.'], 422);
        }

        $rate = ShipperVolumeRate::create($payload);
        return response()->json($rate, 201);
    }

    // Update a volume rate
    public function update(UpdateShipperVolumeRateRequest $request, ShipperVolumeRate $rate)
    {
        $rate->update($request->validated());
        return $rate;
    }

    public function destroy(ShipperVolumeRate $rate)
    {
        $rate->delete();
        return response()->noContent();
    }
}
