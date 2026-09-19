<?php

namespace App\Http\Controllers;

use App\Models\ProductVendorOffer;
use Illuminate\Http\Request;

class VendorOfferController extends Controller
{
    public function update(Request $request, int $id)
    {
        abort_unless($request->user()?->can('vendor requests'), 403);
        $data = $request->validate(['action' => ['required', 'in:activate,deactivate,remove,restore']]);
        $offer = ProductVendorOffer::withTrashed()->findOrFail($id);
        if ($data['action'] === 'restore') {
            $master = \App\Models\ProductMaster::withTrashed()->findOrFail($offer->Products_Id);
            abort_if($master->trashed(), 409, 'Restore the shared catalogue product first, then restore this seller offer.');
        }
        match ($data['action']) {
            'activate' => $offer->update(['Is_Active' => true]),
            'deactivate' => $offer->update(['Is_Active' => false]),
            'remove' => $offer->delete(),
            'restore' => $offer->restore(),
        };

        return response()->json(['success' => true, 'message' => 'Seller offer updated.']);
    }
}
