<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperContact extends Model
{
    protected $table = 'Shipper_Contacts_T';

    protected $fillable = [
        'Shippers_Id','Shippers_Contact_Name','Shippers_Contact_Position',
        'Shippers_Contact_Office_No','Shippers_Contact_GSM_No',
        'Shippers_Contact_Email_Address','Shippers_Is_Primary'
    ];

    protected $casts = [
        'Shippers_Is_Primary' => 'boolean',
    ];

    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }
}
