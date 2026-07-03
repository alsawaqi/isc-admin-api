<?php

namespace App\Http\Controllers;

use App\Models\Shipper;
use App\Models\ShipperContact;
use App\Http\Requests\StoreShipperContactRequest;
use App\Http\Requests\UpdateShipperContactRequest;
use App\Http\Resources\ShipperContactResource;
use Illuminate\Support\Facades\Schema;

use Illuminate\Http\Request;

class ShipperContactController extends Controller
{
    public function index(Shipper $shipper)
    {
        $contacts = $shipper->contacts()
            ->with(['title', 'designation'])
            ->orderByDesc('Shippers_Is_Primary')
            ->orderBy('id')
            ->get();

        return ShipperContactResource::collection($contacts);
    }

    public function store(StoreShipperContactRequest $request, Shipper $shipper)
    {
        $payload = array_merge(
            $this->guardLookupColumns($request->validated()),
            ['Shippers_Id' => $shipper->id]
        );

        // If marking primary, unmark others
        if (!empty($payload['Shippers_Is_Primary'])) {
            ShipperContact::where('Shippers_Id', $shipper->id)->update(['Shippers_Is_Primary' => false]);
        }

        $contact = ShipperContact::create($payload);
        return new ShipperContactResource($contact->load(['title', 'designation']));
    }

    public function update(UpdateShipperContactRequest $request, Shipper $shipper, ShipperContact $contact)
    {
        // int casts: pdo_sqlsrv returns BIGINT columns as strings, so a strict
        // === against the (int) shipper key would always 404.
        abort_unless((int) $contact->Shippers_Id === (int) $shipper->id, 404);
        $payload = $this->guardLookupColumns($request->validated());

        if (array_key_exists('Shippers_Is_Primary', $payload) && $payload['Shippers_Is_Primary']) {
            ShipperContact::where('Shippers_Id', $shipper->id)->where('id', '<>', $contact->id)->update(['Shippers_Is_Primary' => false]);
        }

        $contact->update($payload);
        return new ShipperContactResource($contact->load(['title', 'designation']));
    }

    public function destroy(Shipper $shipper, ShipperContact $contact)
    {
        abort_unless((int) $contact->Shippers_Id === (int) $shipper->id, 404);
        $contact->delete();
        return response()->noContent();
    }

    /**
     * Drop Title_Id / Designation_Id from the payload when the columns are not
     * present yet (shared-DB deploys can lag behind code).
     */
    private function guardLookupColumns(array $payload): array
    {
        if (array_key_exists('Title_Id', $payload)
            && ! Schema::hasColumn('Shipper_Contacts_T', 'Title_Id')) {
            unset($payload['Title_Id']);
        }

        if (array_key_exists('Designation_Id', $payload)
            && ! Schema::hasColumn('Shipper_Contacts_T', 'Designation_Id')) {
            unset($payload['Designation_Id']);
        }

        return $payload;
    }
}
