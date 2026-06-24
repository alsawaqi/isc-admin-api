<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyalityPointsHistory extends Model
{
    protected $table = 'System_Parameter_Loyalty_Points_History_T';

    protected $guarded = [];

    protected $casts = [
        'Current_Point' => 'decimal:3',
        'Previous_Point' => 'decimal:3',
        'Current_Earn_Amount' => 'decimal:3',
        'Previous_Earn_Amount' => 'decimal:3',
        'Current_Earn_Points' => 'decimal:3',
        'Previous_Earn_Points' => 'decimal:3',
        'Current_Redeem_Points' => 'decimal:3',
        'Previous_Redeem_Points' => 'decimal:3',
        'Current_Redeem_Amount' => 'decimal:3',
        'Previous_Redeem_Amount' => 'decimal:3',
    ];
}
