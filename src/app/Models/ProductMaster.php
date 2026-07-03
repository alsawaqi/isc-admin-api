<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cviebrock\EloquentSluggable\Sluggable;

class ProductMaster extends Model
{

    use Sluggable;
    use SoftDeletes;

    protected $table = 'Products_Master_T';

    protected $guarded = [];



     public function sluggable(): array
    {
        return [
            'Slug' => [
                'source' => 'Product_Name'
            ]
        ];
    }

    public function specs(){
            return $this->hasMany(ProductSpecificationProduct::class, 'Product_Id');
        }

    public function department()
    {
        return $this->belongsTo(ProductDepartments::class, 'Product_Department_Id', 'id');
    }

    public function subDepartment()
    {
        return $this->belongsTo(ProductSubDepartment::class, 'Product_Sub_Department_Id', 'id');
    }

    public function subSubDepartment()
    {
        return $this->belongsTo(ProductSubSubDepartment::class, 'Product_Sub_Sub_Department_Id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(VendorMaster::class, 'Vendor_Id', 'id');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class, 'Products_Id', 'id');
    }

    /**
     * Quantity-tier bulk prices, ordered so the tier table always renders
     * lowest-quantity first.
     */
    public function bulkPrices()
    {
        return $this->hasMany(ProductBulkPrice::class, 'Products_Id', 'id')
            ->orderBy('Min_Qty');
    }

    public function questions()
    {
        return $this->hasMany(ProductQuestion::class, 'Products_Id', 'id');
    }
}
