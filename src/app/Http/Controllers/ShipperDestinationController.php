<?php

namespace App\Http\Controllers;


use App\Models\Shipper;
use App\Models\ShipperDestination;
use App\Http\Requests\StoreShipperDestinationRequest;
use App\Http\Requests\UpdateShipperDestinationRequest;
use Illuminate\Http\Request;

class ShipperDestinationController extends Controller
{
    public function index(Request $request, Shipper $shipper)
    {
        $q = $shipper->destinations()->select(
            'id',
            'Shippers_Id',
            'Shippers_Destination_Country',
            'Shippers_Destination_Region',
            'Shippers_Destination_District',
            'Shippers_Destination_Rate_Applicability',
            'Shippers_Destination_Country_Preference',
            'Shippers_Destination_Region_Preference',
            'Shippers_Destination_District_Preference',
            'created_at'
        );

        if ($c = $request->input('country'))  $q->where('Shippers_Destination_Country', $c);
        if ($r = $request->input('region'))   $q->where('Shippers_Destination_Region', $r);
        if ($d = $request->input('district')) $q->where('Shippers_Destination_District', $d);

        return $q->orderBy('Shippers_Destination_Country')
            ->orderBy('Shippers_Destination_Region')
            ->orderBy('Shippers_Destination_District')
            ->paginate($request->integer('per_page', 20));
    }

    public function store(StoreShipperDestinationRequest $request, Shipper $shipper)
    {
        $payload = array_merge($request->validated(), ['Shippers_Id' => $shipper->id]);

        // enforce uniqueness combo like the DB unique index
        $exists = ShipperDestination::where('Shippers_Id', $shipper->id)
            ->where('Shippers_Destination_Country', $payload['Shippers_Destination_Country'])
            ->where('Shippers_Destination_Region',  $payload['Shippers_Destination_Region'])
            ->where('Shippers_Destination_District', $payload['Shippers_Destination_District'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Destination already exists for this shipper.'], 422);
        }

        $dest = ShipperDestination::create($payload);
        return response()->json($dest, 201);
    }

    public function show(ShipperDestination $destination)
    {
        return $destination;
    }

    public function update(UpdateShipperDestinationRequest $request, ShipperDestination $destination)
    {
        $destination->update($request->validated());
        return $destination;
    }

    public function destroy(ShipperDestination $destination)
    {
        $destination->delete();
        return response()->noContent();
    }
}
