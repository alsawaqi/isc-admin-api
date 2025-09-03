<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPackageDetails extends Model
{
    protected $table = 'Orders_Packaging_Details_T';

    protected $guarded = [];


    public function detail()
    {
        return $this->belongsTo(OrdersPlacedDetails::class, 'Orders_Placed_Details_Id');
    }
    public function packedBy()
    {
        return $this->belongsTo(User::class, 'Packed_By');
    }
}
