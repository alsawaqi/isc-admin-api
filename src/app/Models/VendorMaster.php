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
        'Business_Type',
        'Contact_Person_Name',
        'Contact_Person_Title',
        'Contact_Person_Email',
        'Contact_Person_Phone',
        'Bank_Name',
        'Bank_Account_Name',
        'Bank_Account_Number',
        'Bank_IBAN',
        'Bank_Swift_Code',
        'Payout_Method',
        'Payout_Status',


        'Country_Id',
        'Region_Id',
        'District_Id',
        'City_Id',

        'Status',
        'Is_Active',
        'Approval_Status',
        'Onboarding_Status',
        'Onboarding_Completeness',
        'Approved_By',
        'Approved_At',
        'Approval_Note',
        'Submitted_For_Approval_At',
        'Created_By',
        'Updated_By',
    ];

    protected $casts = [
        'Is_Active' => 'boolean',
        'Onboarding_Completeness' => 'integer',
        'Approved_At' => 'datetime',
        'Submitted_For_Approval_At' => 'datetime',
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

    public function documents()
    {
        return $this->hasMany(VendorDocument::class, 'Vendor_Id');
    }
}
