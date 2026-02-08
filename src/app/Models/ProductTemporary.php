<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductTemporary extends Model
{
    use SoftDeletes;

    protected $table = 'Products_Temporary_T';

    protected $fillable = [
        'Vendor_Id',
        'Temp_Product_Code',

        'Product_Name',
        'Product_Name_Ar',
        'Description',
        'Description_Ar',

        'Product_Department_Id',
        'Product_Sub_Department_Id',
        'Product_Sub_Sub_Department_Id',

        'Product_Brand_Id',
        'Product_Manufacture_Id',
        'Product_Type_Id',

        'Product_Price',
        'Product_Cost',
        'Product_Stock',

        'Weight_Kg',
        'Length_Cm',
        'Width_Cm',
        'Height_Cm',
        'Volume_Cbm',

        'Submission_Status',
        'Submitted_By',
        'Submitted_At',
        'Reviewed_By',
        'Reviewed_At',
        'Rejection_Reason',
        'Approved_Product_Id',

        'Created_By',
        'Updated_By',
    ];

    protected $casts = [
        'Vendor_Id' => 'integer',
        'Product_Department_Id' => 'integer',
        'Product_Sub_Department_Id' => 'integer',
        'Product_Sub_Sub_Department_Id' => 'integer',
        'Product_Brand_Id' => 'integer',
        'Product_Manufacture_Id' => 'integer',
        'Product_Type_Id' => 'integer',
        'Product_Stock' => 'integer',

        'Product_Price' => 'float',
        'Product_Cost' => 'float',
        'Weight_Kg' => 'float',
        'Length_Cm' => 'float',
        'Width_Cm' => 'float',
        'Height_Cm' => 'float',
        'Volume_Cbm' => 'float',

        'Submitted_At' => 'datetime',
        'Reviewed_At' => 'datetime',
    ];


    // 🔹 Category Relations (for showing names in admin)
public function department()
{
    return $this->belongsTo(ProductDepartments::class, 'Product_Department_Id');
}

public function subDepartment()
{
    return $this->belongsTo(ProductSubDepartment::class, 'Product_Sub_Department_Id');
}

public function subSubDepartment()
{
    return $this->belongsTo(ProductSubSubDepartment::class, 'Product_Sub_Sub_Department_Id');
}

public function brand()
{
    return $this->belongsTo(ProductBrands::class, 'Product_Brand_Id');
}

public function manufacture()
{
    return $this->belongsTo(ProductManufacture::class, 'Product_Manufacture_Id');
}

public function type()
{
    return $this->belongsTo(ProductTypes::class, 'Product_Type_Id');
}

// 🔹 Specs attached to temp product
public function specs()
{
    return $this->hasMany(ProductSpecificationTemp::class, 'Product_Temporary_Id');
}


    // ✅ If Vendor_Id points to Vendors_Master_T.id (default), this is correct.
    public function vendor()
    {
        return $this->belongsTo(VendorMaster::class, 'Vendor_Id');
    }

    public function images()
    {
        return $this->hasMany(ProductTemporaryImage::class, 'Products_Temporary_Id');
    }

    public function defaultImage()
    {
        return $this->hasOne(ProductTemporaryImage::class, 'Products_Temporary_Id')
            ->where('Is_Default', 1);
    }
}
