<?php

namespace App\Http\Controllers;

use App\Models\Shipper;
use App\Models\ShipperContact;
use App\Http\Requests\StoreShipperContactRequest;
use App\Http\Requests\UpdateShipperContactRequest;
use App\Http\Resources\ShipperContactResource;

use Illuminate\Http\Request;

class ShipperContactController extends Controller
{
    public function index(Shipper $shipper)
    {
        $contacts = $shipper->contacts()->orderByDesc('Shippers_Is_Primary')->orderBy('id')->get();
        return ShipperContactResource::collection($contacts);
    }

    public function store(StoreShipperContactRequest $request, Shipper $shipper)
    {
        $payload = array_merge($request->validated(), ['Shippers_Id' => $shipper->id]);

        // If marking primary, unmark others
        if (!empty($payload['Shippers_Is_Primary'])) {
            ShipperContact::where('Shippers_Id', $shipper->id)->update(['Shippers_Is_Primary' => false]);
        }

        $contact = ShipperContact::create($payload);
        return new ShipperContactResource($contact);
    }

    public function update(UpdateShipperContactRequest $request, Shipper $shipper, ShipperContact $contact)
    {
        abort_unless($contact->Shippers_Id === $shipper->id, 404);
        $payload = $request->validated();

        if (array_key_exists('Shippers_Is_Primary', $payload) && $payload['Shippers_Is_Primary']) {
            ShipperContact::where('Shippers_Id', $shipper->id)->where('id', '<>', $contact->id)->update(['Shippers_Is_Primary' => false]);
        }

        $contact->update($payload);
        return new ShipperContactResource($contact);
    }

    public function destroy(Shipper $shipper, ShipperContact $contact)
    {
        abort_unless($contact->Shippers_Id === $shipper->id, 404);
        $contact->delete();
        return response()->noContent();
    }
}
