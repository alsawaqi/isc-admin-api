<?php

namespace App\Http\Controllers;

use App\Models\ShipperDestination;
use App\Models\ShipperShippingRate;
use App\Http\Requests\StoreShipperShippingRateRequest;
use App\Http\Requests\UpdateShipperShippingRateRequest;
use Illuminate\Http\Request;

class ShipperShippingRateController extends Controller
{
    public function index(Request $request, ShipperDestination $destination)
    {
        return ShipperShippingRate::where('Shippers_Destination_Id', $destination->id)
            ->select(
                'id',
                'Shippers_Id',
                'Shippers_Destination_Id',
                'Shippers_Destination_Country_Id',
                'Shippers_Destination_Region_Id',
                'Shippers_Destination_District_Id',
                'Shippers_Destination_Rate_Volume',
                'Shippers_Destination_Rate_Weight',
                'Shippers_Destination_Rate_Applicable',
                'created_at'
            )
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));
    }

    public function store(StoreShipperShippingRateRequest $request, ShipperDestination $destination)
    {
        $payload = $request->validated();
        $payload['Shippers_Id'] = $destination->Shippers_Id;
        $payload['Shippers_Destination_Id'] = $destination->id;

        // If you want only one row per destination, enforce:
        $exists = ShipperShippingRate::where('Shippers_Destination_Id', $destination->id)->exists();
        if ($exists) {
            return response()->json(['message' => 'Shipping rate already exists for this destination.'], 422);
        }

        $rate = ShipperShippingRate::create($payload);
        return response()->json($rate, 201);
    }

    public function update(UpdateShipperShippingRateRequest $request, ShipperShippingRate $rate)
    {
        $rate->update($request->validated());
        return $rate;
    }

    public function destroy(ShipperShippingRate $rate)
    {
        $rate->delete();
        return response()->noContent();
    }
}
