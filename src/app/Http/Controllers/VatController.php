<?php

namespace App\Http\Controllers;

use App\Models\Vat;
use Illuminate\Http\Request;

class VatController extends Controller
{

    public function index()
    {
        $vat =  Vat::first();
        return response()->json(
            $vat->Vat
        );
    }


    public function update(Request $request)
    {
        $request->validate([
            'Vat' => 'required|numeric|min:0',
        ]);

        try {
            $vat = Vat::first();
            $vat->Vat = $request->input('Vat');
            $vat->save();

            return response()->json(['message' => 'VAT percentage updated successfully.']);
        } catch (\Exception $e) {
            return response()->json(['error' =>  $e->getMessage()], 500);
        }
    }
}
