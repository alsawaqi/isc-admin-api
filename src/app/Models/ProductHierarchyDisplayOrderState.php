<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductHierarchyDisplayOrderState extends Model
{
    protected $table = 'Product_Hierarchy_Display_Order_State_T';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'Revision' => 'integer',
        ];
    }
}
