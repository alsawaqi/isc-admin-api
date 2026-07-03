<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperContact extends Model
{
    protected $table = 'Shipper_Contacts_T';

    protected $fillable = [
        'Shippers_Id','Contact_Department_Id','Shippers_Contact_Name','Shippers_Contact_Position',
        'Shippers_Contact_Office_No','Shippers_Contact_GSM_No',
        'Shippers_Contact_Email_Address','Shippers_Is_Primary',
        'Title_Id','Designation_Id'
    ];

    protected $casts = [
        'Shippers_Is_Primary' => 'boolean',
    ];

    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function department()
    {
        return $this->belongsTo(ContactDepartments::class, 'Contact_Department_Id', 'id');
    }

    public function title()
    {
        return $this->belongsTo(Title::class, 'Title_Id', 'id');
    }

    public function designation()
    {
        return $this->belongsTo(Designation::class, 'Designation_Id', 'id');
    }
}
