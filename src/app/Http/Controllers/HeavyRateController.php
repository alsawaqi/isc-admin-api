<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHeavyRateRequest;
use App\Http\Requests\UpdateHeavyRateRequest;
use App\Models\ShipperDestination;
use App\Models\ShipperHeavyRate;
use App\Models\HeavyVehicle;
use Illuminate\Http\Request;

class HeavyRateController extends Controller
{
    // List heavy rates for a destination (with vehicle name)
    public function index(Request $request, ShipperDestination $destination)
    {
        $q = ShipperHeavyRate::query()
            ->where('Shippers_Destination_Id', $destination->id)
            ->with(['vehicle:id,Shippers_Vehicle_Name,Shippers_Id'])
            ->select('id','Shippers_Id','Shippers_Destination_Id','Shippers_Vehicle_Id',
                     'Shippers_Flat_Rate','Shippers_Hourly_Rate','Shippers_Min_Hours',
                     'Shippers_Currency','created_at');

        if ($vid = $request->integer('vehicle_id')) {
            $q->where('Shippers_Vehicle_Id', $vid);
        }

        return $q->orderBy('Shippers_Vehicle_Id')
                 ->paginate($request->integer('per_page', 20));
    }

    // Create heavy rate for a destination
    public function store(StoreHeavyRateRequest $request, ShipperDestination $destination)
    {
        $payload = $request->validated();

        // ensure vehicle belongs to same shipper
        $vehicle = HeavyVehicle::where('id', $payload['Shippers_Vehicle_Id'])
            ->where('Shippers_Id', $destination->Shippers_Id)
            ->first();
        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle does not belong to this shipper.'], 422);
        }

        $payload['Shippers_Id'] = $destination->Shippers_Id;
        $payload['Shippers_Destination_Id'] = $destination->id;

        // uniqueness: one rate per (destination, vehicle)
        $exists = ShipperHeavyRate::where('Shippers_Destination_Id', $destination->id)
            ->where('Shippers_Vehicle_Id', $payload['Shippers_Vehicle_Id'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Rate already exists for this destination & vehicle.'], 422);
        }

        $rate = ShipperHeavyRate::create($payload);
        $rate->load(['vehicle:id,Shippers_Vehicle_Name,Shippers_Id']);
        return response()->json($rate, 201);
    }

    public function update(UpdateHeavyRateRequest $request, ShipperHeavyRate $rate)
    {
        $data = $request->validated();

        // If vehicle is changing, ensure same shipper
        if (isset($data['Shippers_Vehicle_Id'])) {
            $ok = HeavyVehicle::where('id', $data['Shippers_Vehicle_Id'])
                ->where('Shippers_Id', $rate->Shippers_Id)
                ->exists();
            if (!$ok) {
                return response()->json(['message' => 'Vehicle does not belong to this shipper.'], 422);
            }
        }

        $rate->update($data);
        return $rate->load(['vehicle:id,Shippers_Vehicle_Name,Shippers_Id']);
    }

    public function destroy(ShipperHeavyRate $rate)
    {
        $rate->delete();
        return response()->noContent();
    }
}