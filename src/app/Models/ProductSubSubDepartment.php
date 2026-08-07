<?php

namespace App\Models;

use App\Support\HierarchyName;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;

class ProductSubSubDepartment extends Model
{
    //

    use Sluggable;

    protected $table = 'Products_Sub_Sub_Department_T';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->Product_Sub_Sub_Department_Name = HierarchyName::display($model->Product_Sub_Sub_Department_Name);
            $model->Name_Fingerprint = HierarchyName::fingerprint($model->Product_Sub_Sub_Department_Name);
        });
    }

    protected function casts(): array
    {
        return [
            'Display_Order' => 'integer',
        ];
    }

    public function scopeDisplayOrdered($query)
    {
        return $query->orderBy('Display_Order')->orderBy('id');
    }

    public function sluggable(): array
    {
        return [
            'Slug' => [
                'source' => 'Product_Sub_Sub_Department_Name',
            ],
        ];
    }

    public function productSubDepartment()
    {
        return $this->belongsTo(ProductSubDepartment::class, 'Product_Sub_Department_Id');
    }

    public function subDepartment()
    {
        return $this->belongsTo(ProductSubDepartment::class, 'Product_Sub_Department_Id', 'id');
    }
}
