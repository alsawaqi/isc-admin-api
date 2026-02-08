<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorMaster extends Model
{
    use SoftDeletes;

    protected $table = 'Vendors_Master_T';


    protected $fillable = [
        'Vendor_Code',
        'Vendor_Name',
        'Trade_Name',
        'CR_Number',
        'VAT_Number',
        'Email_1',
        'Phone_No',
        'Address_Line1',
        'Address_Line2',
        'Postal_Code',
        'PO_Box',


        'Country_Id',
        'Region_Id',
        'District_Id',
        'City_Id',

        'Status',
        'Is_Active',
        'Created_By',
        'Updated_By',
    ];


    public function productsTemp()
    {
        return $this->hasMany(ProductTemporary::class, 'Vendor_Id');
    }



    public function productsTempImage()
    {
        return $this->hasMany(ProductTemporaryImage::class, 'Vendor_Id');
    }

    public function products()
    {
        return $this->hasMany(ProductMaster::class, 'Vendor_Id');
    }

    public function productsImage()
    {
        return $this->hasMany(ProductImages::class, 'Vendor_Id');
    }
}
