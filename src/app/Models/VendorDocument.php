<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorDocument extends Model
{
    use SoftDeletes;

    protected $table = 'Vendor_Documents_T';

    protected $guarded = [];

    protected $casts = [
        'Vendor_Id' => 'integer',
        'File_Size' => 'integer',
        'Uploaded_By_Vendor_User_Id' => 'integer',
        'Reviewed_By_Admin_Id' => 'integer',
        'Reviewed_At' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(VendorMaster::class, 'Vendor_Id');
    }
}
