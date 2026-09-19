<?php

namespace App\Http\Controllers;

use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use App\Models\ProductVendorOffer;
use App\Services\VendorOffers;
use Illuminate\Http\Request;

class VendorProductMatchesController extends Controller
{
    public function index(Request $request, int $tempId)
    {
        abort_unless($request->user()?->can('vendor requests'), 403);
        abort_unless(VendorOffers::ready(), 409, 'Vendor offers migration is required.');
        $request->validate(['q' => ['nullable', 'string', 'max:150']]);
        $temp = ProductTemporary::withTrashed()->findOrFail($tempId);
        $q = trim((string) $request->query('q', ''));
        $products = ProductMaster::query()->with(['specs.description', 'specs.value'])
            ->where('Product_Sub_Sub_Department_Id', $temp->Product_Sub_Sub_Department_Id)
            ->when($q !== '', fn ($query) => $query->where(function ($search) use ($q) {
                $search->where('Product_Name', 'like', "%$q%")
                    ->orWhere('Product_Code', 'like', "%$q%")
                    ->orWhere('Product_Sku', 'like', "%$q%");
            }))
            ->orderByRaw('CASE WHEN Product_Name = ? THEN 0 ELSE 1 END', [$temp->Product_Name])
            ->orderByRaw('CASE WHEN Product_Brand_Id = ? THEN 0 ELSE 1 END', [$temp->Product_Brand_Id])
            ->orderBy('id')->limit(50)->get();
        $alreadyLinked = ProductVendorOffer::withTrashed()->where('Vendor_Id', $temp->Vendor_Id)->whereIn('Products_Id', $products->pluck('id'))->pluck('Products_Id');

        return response()->json(['data' => $products->map(fn ($product) => [
            'id' => $product->id, 'name' => $product->Product_Name, 'code' => $product->Product_Code,
            'sku' => $product->Product_Sku, 'description' => $product->Product_Description,
            'same_name' => mb_strtolower(trim($product->Product_Name)) === mb_strtolower(trim($temp->Product_Name)),
            'same_brand' => (int) $product->Product_Brand_Id === (int) $temp->Product_Brand_Id,
            'already_linked' => $alreadyLinked->contains($product->id),
            'specifications' => $product->specs->map(fn ($spec) => [
                'name' => $spec->description?->Product_Specification_Description_Name,
                'value' => $spec->value?->value,
            ]),
        ])]);
    }
}
