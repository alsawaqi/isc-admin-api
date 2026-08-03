<?php

namespace App\Models;

use App\Support\HierarchyName;
use Illuminate\Database\Eloquent\Model;

class ProductSubDepartment extends Model
{
   protected $table = 'Products_Sub_Department_T';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->Sub_Department_Name = HierarchyName::display($model->Sub_Department_Name);
            $model->Name_Fingerprint = HierarchyName::fingerprint($model->Sub_Department_Name);
        });
    }

    public function productDepartment()
    {
        return $this->belongsTo(ProductDepartments::class, 'Products_Departments_Id');
    }

    public function subSubDepartments()
    {
        return $this->hasMany(ProductSubSubDepartment::class, 'Product_Sub_Department_Id', 'id');
    }


    public function department()
        {
            return $this->belongsTo(ProductDepartments::class, 'Products_Departments_Id', 'id');
        }
}
