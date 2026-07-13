<?php

namespace App\Models;

use App\Support\Orders\ActualCommerceOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrdersPlaced extends Model
{
    protected $table = 'Orders_Placed_T';
    protected $guarded = [];

    /**
     * Exclude provisional SmartBox checkout drafts from order, sales and
     * commission surfaces. The cancelled/failed rows remain available to the
     * payment reconciliation trail, but they are not commerce orders.
     */
    public function scopeActualCommerceOrder(Builder $query): Builder
    {
        return $query->where(function (Builder $visible) {
            $visible->whereNull('Payment_Method')
                ->orWhere('Payment_Method', '<>', 'card')
                ->orWhereIn('Payment_Status', ActualCommerceOrder::VISIBLE_CARD_PAYMENT_STATUSES);
        });
    }


    public function customerContact()
    {
        return $this->belongsTo(CustomerContact::class, 'Customers_Contacts_Id');
    }


    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id');
    }

    public function orderlist()
    {
        return $this->hasMany(OrdersPlacedDetails::class, 'Orders_Placed_Id');
    }

    public function transaction()
    {
        return $this->hasOne(SalesTransactionHeader::class, 'Orders_Placed_Id', 'id');
    }


    // app/Models/OrdersPlaced.php
    public function packagingDetails()
    {
        return $this->hasMany(OrderPackageDetails::class, 'Orders_Placed_Id');
    }

    public function processLogs()
    {
        return $this->hasMany(OrderProcessLog::class, 'Orders_Placed_Id');
    }

    public function vendorOrders()
    {
        return $this->hasMany(OrdersPlacedVendors::class, 'Orders_Placed_Id');
    }


    public function location(): mixed
    {
        return $this->belongsTo(Locations::class, 'Location_Id');
    }
}
