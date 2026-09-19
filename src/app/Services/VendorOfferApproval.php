<?php

namespace App\Services;

use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use App\Models\ProductVendorOffer;
use App\Support\Pricing\BulkPriceRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VendorOfferApproval
{
    public function validateMatch(ProductTemporary $temp, ProductMaster $master): void
    {
        foreach (['Product_Sub_Sub_Department_Id', 'Product_Type_Id', 'Product_Brand_Id', 'Product_Manufacture_Id'] as $field) {
            if ($temp->$field && $master->$field && (int) $temp->$field !== (int) $master->$field) {
                throw ValidationException::withMessages(['match_product_id' => 'The selected product has a different category, type, brand or manufacturer. Review the match.']);
            }
        }
        $masterSpecs = $master->specs()->pluck('product_specification_value_id', 'Product_Specification_Description_Id');
        foreach ($temp->specs as $spec) {
            $existing = $masterSpecs->get($spec->Product_Specification_Description_Id);
            if ($existing && $spec->product_specification_value_id && (int) $existing !== (int) $spec->product_specification_value_id) {
                throw ValidationException::withMessages(['match_product_id' => 'Specifications conflict with the selected master product. Different variants must remain separate products.']);
            }
        }
        if (ProductVendorOffer::withTrashed()->where('Products_Id', $master->id)->where('Vendor_Id', $temp->Vendor_Id)->exists()) {
            throw ValidationException::withMessages(['match_product_id' => 'This vendor already has an offer for that product. Use its existing listing to request changes.']);
        }
    }

    public function create(ProductTemporary $temp, ProductMaster $master, ?string $commissionType, ?float $commissionValue): ProductVendorOffer
    {
        $floor = $master->Minimum_Selling_Price;
        if ($floor !== null && (float) $temp->Product_Price < (float) $floor) {
            throw ValidationException::withMessages(['price' => 'The vendor price is below the approved minimum selling price.']);
        }
        $offer = ProductVendorOffer::create([
            'Products_Id' => $master->id, 'Vendor_Id' => $temp->Vendor_Id,
            'Product_Price' => $temp->Product_Price, 'Product_Cost' => $temp->Product_Cost,
            'Minimum_Selling_Price' => $floor, 'Product_Stock' => $temp->Product_Stock,
            'Status' => $temp->Product_Stock > 0 ? 'available' : 'out_of_stock', 'Is_Active' => true,
            'Commission_Type' => $commissionType, 'Commission_Value' => $commissionValue, 'Approved_By' => Auth::id(),
        ]);
        $tiers = Schema::hasTable('Products_Temporary_Bulk_Prices_T')
            ? DB::table('Products_Temporary_Bulk_Prices_T')->where('Products_Temporary_Id', $temp->id)->get()->map(fn ($tier) => [
                'min_qty' => $tier->Min_Qty, 'max_qty' => $tier->Max_Qty, 'unit_price' => $tier->Unit_Price,
            ])->all() : [];
        $this->replaceTiers($offer, $tiers);

        return $offer;
    }

    public function replaceTiers(ProductVendorOffer $offer, array $tiers): void
    {
        $errors = BulkPriceRules::validateSet($tiers, $offer->Minimum_Selling_Price !== null ? (float) $offer->Minimum_Selling_Price : null);
        if ($errors) {
            throw new \InvalidArgumentException('Bulk prices: '.implode(' ', $errors));
        }
        $offer->bulkPrices()->delete();
        foreach ($tiers as $tier) {
            $max = $tier['max_qty'] ?? $tier['Max_Qty'] ?? null;
            $offer->bulkPrices()->create([
                'Min_Qty' => (int) ($tier['min_qty'] ?? $tier['Min_Qty']),
                'Max_Qty' => $max === null || $max === '' ? null : (int) $max,
                'Unit_Price' => round((float) ($tier['unit_price'] ?? $tier['Unit_Price']), 3),
            ]);
        }
    }
}
