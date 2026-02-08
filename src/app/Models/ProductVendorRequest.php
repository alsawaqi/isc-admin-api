<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVendorRequest extends Model
{

    protected $table = 'Products_Vendor_Requests_T';
    
    protected $fillable = [
        'Products_Temporary_Id',
        'Products_Id',
        'Vendor_Id',
        'Status',
        'Comment',
        'Action_By_User_Id',
        'Action_By_Role',
        'Action_At',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships (optional but helpful)
    |--------------------------------------------------------------------------
    */

    public function temporaryProduct()
    {
        // Adjust foreign key if your PK is named differently
        return $this->belongsTo(ProductTemporary::class, 'Products_Temporary_Id');
    }

    public function masterProduct()
    {
        // Adjust if your master PK is not Products_Id
        return $this->belongsTo(Product::class, 'Products_Id');
    }

    public function vendor()
    {
        // Replace Vendor::class & key names with your real model
        return $this->belongsTo(VendorMaster::class, 'Vendor_Id', 'Vendor_Id');
    }

    public function actionByUser()
    {
        return $this->belongsTo(User::class, 'Action_By_User_Id');
    }
}