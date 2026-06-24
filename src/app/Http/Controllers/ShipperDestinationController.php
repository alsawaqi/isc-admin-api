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
	            'Shippers_Destination_Country_Id',
	            'Shippers_Destination_Region_Id',
	            'Shippers_Destination_District_Id',
	            'Shippers_Destination_Rate_Applicability',
	            'Shippers_Destination_Country_Preference',
	            'Shippers_Destination_Region_Preference',
            'Shippers_Destination_District_Preference',
            'created_at'
        );

	        if ($c = $request->input('country_id'))  $q->where('Shippers_Destination_Country_Id', $c);
	        if ($r = $request->input('region_id'))   $q->where('Shippers_Destination_Region_Id', $r);
	        if ($d = $request->input('district_id')) $q->where('Shippers_Destination_District_Id', $d);

	        return $q->orderBy('Shippers_Destination_Country_Id')
	            ->orderBy('Shippers_Destination_Region_Id')
	            ->orderBy('Shippers_Destination_District_Id')
	            ->paginate($request->integer('per_page', 20));
	    }

    public function store(StoreShipperDestinationRequest $request, Shipper $shipper)
    {
        $payload = array_merge($request->validated(), ['Shippers_Id' => $shipper->id]);

        // enforce uniqueness combo like the DB unique index
	        $exists = ShipperDestination::where('Shippers_Id', $shipper->id)
	            ->where('Shippers_Destination_Country_Id', $payload['Shippers_Destination_Country_Id'] ?? null)
	            ->where('Shippers_Destination_Region_Id',  $payload['Shippers_Destination_Region_Id'] ?? null)
	            ->where('Shippers_Destination_District_Id', $payload['Shippers_Destination_District_Id'] ?? null)
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
