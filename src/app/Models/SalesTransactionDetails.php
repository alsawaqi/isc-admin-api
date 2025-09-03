<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTransactionDetails extends Model
{
    protected $table = 'Sales_Transactions_Details_T';

    protected $guarded = [];

    public function header()
    {
        return $this->belongsTo(SalesTransactionHeader::class, 'Sales_Transaction_Header_Id', 'id');
    }
}
