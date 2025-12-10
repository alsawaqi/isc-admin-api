<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHeavyVehicleRequest;
use App\Http\Requests\UpdateHeavyVehicleRequest;
use App\Models\Shipper;
use App\Models\HeavyVehicle;
use Illuminate\Http\Request;

class HeavyVehicleController extends Controller
{
    // List vehicles for a shipper
    public function index(Request $request, Shipper $shipper)
    {
        $q = $shipper->heavyVehicles()->select('id', 'Shippers_Id', 'Shippers_Vehicle_Name', 'Shippers_Vehicle_Capacity_Ton', 'created_at');

        if ($s = $request->string('search')->toString()) {
            $q->where('Shippers_Vehicle_Name', 'like', "%$s%");
        }

        return $q->orderBy('Shippers_Vehicle_Name')
            ->paginate($request->integer('per_page', 20));
    }

    // Create vehicle for a shipper
    public function store(StoreHeavyVehicleRequest $request, Shipper $shipper)
    {
        $payload = array_merge($request->validated(), ['Shippers_Id' => $shipper->id]);

        // Optional uniqueness: one name per shipper
        $exists = HeavyVehicle::where('Shippers_Id', $shipper->id)
            ->where('Shippers_Vehicle_Name', $payload['Shippers_Vehicle_Name'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Vehicle name already exists for this shipper.'], 422);
        }

        $vehicle = HeavyVehicle::create($payload);
        return response()->json($vehicle, 201);
    }

    public function show(HeavyVehicle $vehicle)
    {
        return $vehicle;
    }

    public function update(UpdateHeavyVehicleRequest $request, HeavyVehicle $vehicle)
    {
        $vehicle->update($request->validated());
        return $vehicle;
    }

    public function destroy(HeavyVehicle $vehicle)
    {
        $vehicle->delete();
        return response()->noContent();
    }
}
