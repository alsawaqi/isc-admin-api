<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class SecxVendorUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'Secx_Vendors_Users_Master_T';

    protected $fillable = [
        'Vendor_Id',
        'User_Id',
        'User_Name',
        'email',
        'Email_verified_at',
        'password',
        'remember_token',
        'Login_Password',
        'Merchant_Id',
        'Company_Code',
        'Merchant_Jurisdiction_Code',
        'User_Type_Code',
        'Department_Code',
        'Role_Code',
        'Designation_Code',
        'No_Login',
        'Successful_Login',
        'Status',
        'Password_Changed_Date',
        'Phone',
        'Gsm',
        'FAX',
        'Alternate_Email',
        'Postal_Code',
        'PO_Box',
        'Region_Code',
        'Location_Code',
        'Country_Code',
        'Additional_Rights_Updated_Status',
        'Created_User_Id',
        'Updated_Status',
        'Two_Factor_Secret',
        'Two_Factor_Recovery_Codes',
        'Two_Factor_Confirmed_At',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'Two_Factor_Secret',
        'Two_Factor_Recovery_Codes',
    ];

    protected $casts = [
        'Email_verified_at' => 'datetime',
        'Password_Changed_Date' => 'datetime',
        'Two_Factor_Confirmed_At' => 'datetime',
        'Additional_Rights_Updated_Status' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(VendorMaster::class, 'Vendor_Id');
    }
}
