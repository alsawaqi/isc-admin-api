<?php

use App\Models\SecurityRole;
use Illuminate\Http\Request;
use Laravel\Fortify\RoutePath;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CityController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\ShipperController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\HeavyRateController;
use App\Http\Controllers\HeavyVehicleController;
use App\Http\Controllers\OrdersPlacedController;
use App\Http\Controllers\ProductTypesController;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\ProductBrandsController;
use App\Http\Controllers\ProductImagesController;
use App\Http\Controllers\ProductMasterController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\ShipperContactController;
use App\Http\Controllers\ProductsBarcodesController;
use App\Http\Controllers\ShipperVolumeRateController;
use App\Http\Controllers\ShipperWeightRateController;
use App\Http\Controllers\ContactDepartmentsController;
use App\Http\Controllers\ProductDepartmentsController;
use App\Http\Controllers\ProductManufactureController;
use App\Http\Controllers\ShipperDestinationController;
use App\Http\Controllers\SupportTicketAdminController;
use App\Http\Controllers\ShipperShippingRateController;
use App\Http\Controllers\ProductSubDepartmentController;
use App\Http\Controllers\ProductSubSubDepartmentController;
use App\Http\Controllers\ProductSpecificationProductController;
use App\Http\Controllers\ProductSpecificationDescriptionController;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;





Route::group(['middleware' => 'auth:sanctum'], function () {


    Route::get('/user', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(), // returns array of role names
            'permissions' => $user->getAllPermissions()->pluck('name'), // returns array of permission names
        ]);
    });


    Route::controller(UserController::class)->group(function () {
        Route::post('/users', 'store');
        Route::get('/users-with-roles', 'getUsersWithRoles');
    });



    Route::controller(SupportTicketAdminController::class)->group(function () {
        Route::get('/admin/support-tickets', 'index');
        Route::get('/admin/support-tickets/{id}', 'show');
        Route::post('/admin/support-tickets/{id}/reply', 'reply');
        Route::patch('/admin/support-tickets/{id}/status', 'updateStatus');
    });



    Route::controller(ProductDepartmentsController::class)->group(function () {
        Route::get('/productdepartment', 'index');
        Route::post('/productdepartment', 'store');
        Route::get('/sub-departments/{departmentId}', 'getSubDepartments');
        Route::get('/sub-sub-departments/{subDepartmentId}', 'bySubDepartment');
        Route::delete('/productdepartment/{productdepartment}', 'destroy');
    });



    Route::controller(ProductBrandsController::class)->group(function () {

        Route::get('/productbrands', 'index');
        Route::post('/productbrands', 'store');
    });


    Route::controller(ProductsBarcodesController::class)->group(function () {
        Route::get('/productmaster/{id}/barcodes', 'getProductBarcodes');
        Route::post('/productmaster/{id}/barcodes', 'updateProductBarcodes');
    });


    Route::controller(ProductImagesController::class)->group(function () {
        Route::post('/product-images/{product}', 'uploadImages');
        Route::get('/product-images/{product}', 'getImages');
    });


    Route::controller(ProductMasterController::class)->group(function () {

        Route::get('/productmaster', 'index');
        Route::get('/latest-products', 'getLatestProducts');
        Route::post('/productmaster', 'store');
        Route::get('/productmaster/{id}', 'show');
        Route::put('/productmaster/{id}', 'update');
        Route::delete('/productmaster/{productmaster}', 'destroy');
    });


    Route::controller(CustomersController::class)->group(function () {

        Route::get('/customers', 'index');
    });


    Route::prefix('v1/shipping')->group(function () {
        // Shippers
        Route::get('/shippers', [ShipperController::class, 'index']);
        Route::post('/shippers', [ShipperController::class, 'store']);
        Route::get('/shippers/{id}', [ShipperController::class, 'show']);
        Route::put('/shippers/{id}', [ShipperController::class, 'update']);
        Route::delete('/shippers/{id}', [ShipperController::class, 'destroy']);
        Route::post('/shippers/{shipper}/toggle', [ShipperController::class, 'toggle']);

        // Contacts (nested)
        Route::get('/shippers/{shipper}/contacts', [ShipperContactController::class, 'index']);
        Route::post('/shippers/{shipper}/contacts', [ShipperContactController::class, 'store']);
        Route::put('/shippers/{shipper}/contacts/{contact}', [ShipperContactController::class, 'update']);
        Route::patch('/shippers/{shipper}/contacts/{contact}', [ShipperContactController::class, 'update']);
        Route::delete('/shippers/{shipper}/contacts/{contact}', [ShipperContactController::class, 'destroy']);

        // Destinations
        Route::get('/shippers/{shipper}/destinations', [ShipperDestinationController::class, 'index']);
        Route::post('/shippers/{shipper}/destinations', [ShipperDestinationController::class, 'store']);
        Route::get('/destinations/{destination}', [ShipperDestinationController::class, 'show']);
        Route::put('/destinations/{destination}', [ShipperDestinationController::class, 'update']);
        Route::patch('/destinations/{destination}', [ShipperDestinationController::class, 'update']);
        Route::delete('/destinations/{destination}', [ShipperDestinationController::class, 'destroy']);



        // Shipping Rates (applicability per destination)
        Route::get('/destinations/{destination}/shipping-rates', [ShipperShippingRateController::class, 'index']);
        Route::post('/destinations/{destination}/shipping-rates', [ShipperShippingRateController::class, 'store']);
        Route::put('/shipping-rates/{rate}', [ShipperShippingRateController::class, 'update']);
        Route::patch('/shipping-rates/{rate}', [ShipperShippingRateController::class, 'update']);
        Route::delete('/shipping-rates/{rate}', [ShipperShippingRateController::class, 'destroy']);


        // Volume Rates (nested by destination)
        Route::get('/destinations/{destination}/volume-rates',   [ShipperVolumeRateController::class, 'index']);
        Route::post('/destinations/{destination}/volume-rates',  [ShipperVolumeRateController::class, 'store']);
        Route::put('/volume-rates/{rate}',   [ShipperVolumeRateController::class, 'update']);
        Route::patch('/volume-rates/{rate}', [ShipperVolumeRateController::class, 'update']);
        Route::delete('/volume-rates/{rate}', [ShipperVolumeRateController::class, 'destroy']);

        // Weight Rates (nested by destination)
        Route::get('/destinations/{destination}/weight-rates',   [ShipperWeightRateController::class, 'index']);
        Route::post('/destinations/{destination}/weight-rates',  [ShipperWeightRateController::class, 'store']);
        Route::put('/weight-rates/{rate}',   [ShipperWeightRateController::class, 'update']);
        Route::patch('/weight-rates/{rate}', [ShipperWeightRateController::class, 'update']);
        Route::delete('/weight-rates/{rate}', [ShipperWeightRateController::class, 'destroy']);



        // Heavy Vehicles (per shipper)
        Route::get('/shippers/{shipper}/vehicles', [HeavyVehicleController::class, 'index']);
        Route::post('/shippers/{shipper}/vehicles', [HeavyVehicleController::class, 'store']);
        Route::get('/vehicles/{vehicle}', [HeavyVehicleController::class, 'show']);
        Route::put('/vehicles/{vehicle}', [HeavyVehicleController::class, 'update']);
        Route::patch('/vehicles/{vehicle}', [HeavyVehicleController::class, 'update']);
        Route::delete('/vehicles/{vehicle}', [HeavyVehicleController::class, 'destroy']);

        // Heavy Rates (per destination & vehicle)
        Route::get('/destinations/{destination}/heavy-rates', [HeavyRateController::class, 'index']);
        Route::post('/destinations/{destination}/heavy-rates', [HeavyRateController::class, 'store']);
        Route::put('/heavy-rates/{rate}', [HeavyRateController::class, 'update']);
        Route::patch('/heavy-rates/{rate}', [HeavyRateController::class, 'update']);
        Route::delete('/heavy-rates/{rate}', [HeavyRateController::class, 'destroy']);
    });


    Route::controller(ProductSpecificationProductController::class)->group(function () {
        Route::post('/product-specifications-update', 'storeOrUpdate');
        Route::post('/product-specification-products', 'store');
        Route::get('/product-specifications/{product}', 'getProductSpecificationsForEdit');
    });

    Route::controller(ProductManufactureController::class)->group(function () {
        Route::get('/productmanufacture', 'index');
        Route::post('/productmanufacture', 'store');
    });



    Route::controller(ProductTypesController::class)->group(function () {
        Route::get('/producttype', 'index');
        Route::post('/producttype', 'store');
    });


    Route::controller(ProductSubDepartmentController::class)->group(function () {
        Route::get('/productsubdepartment', 'index');
        Route::get('/product-departments-with-sub', 'getWithSubDepartments');
        Route::post('/productsubdepartment', 'store');
        Route::delete('/productsubdepartment/{productsubdepartment}', 'destroy');
    });


    Route::controller(ProductSpecificationDescriptionController::class)->group(function () {
        Route::get('/product-specifications', 'index');
        Route::get('/product-specification', 'getfilter');
        Route::put('/product-specifications/bulk', 'bulkUpsert');
        Route::post('/product-specifications/bulk', 'store');
        Route::delete('/product-specifications/{id}', 'destroy');
        Route::delete('/product-specification-values/{id}', 'destroyValue');
    });


    Route::controller(ProductSubSubDepartmentController::class)->group(function () {

        Route::get('/full-product-department-tree', 'getFullDepartmentTree');
        Route::get('/sub-sub-departments', 'index');
        Route::post('/sub-sub-departments', 'store');
        Route::delete('/sub-sub-departments/{productsubsub}', 'destroy');
    });



    Route::controller(RolePermissionController::class)->group(function () {
        Route::get('/roles',  'index');
        Route::post('/roles',  'storeRole');
        Route::get('/roles/{id}/permissions',  'getRolePermissions');
        Route::post('/roles/{id}/permissions',  'updateRolePermissions');
        Route::post('/permissions',  'storePermission');
        Route::post('/assign-role',   'assignRole');
    });



    Route::controller(CountryController::class)->group(function () {

        Route::get('/countries', 'index');
        Route::post('/countries', 'store');
    });



    Route::controller(OrdersPlacedController::class)->group(function () {

        Route::get('/orders-placed', 'index');
        Route::get('/orders-placed/packing', 'packing_index');
        Route::get('/orders-placed/dispatch', 'dispatch_index');
        Route::get('/orders-placed/shipment', 'shipment_index');
        Route::get('/orders-placed/delivered', 'delivered_index');






        Route::post('/orders-placed', 'store');



        Route::post('/orders-placed/{id}/pack', 'packing');



        Route::post('/orders-placed/{id}/dispatch', 'dispatch');
        Route::post('/orders-placed/{id}/shipment', 'shipment');
        Route::post('/orders-placed/complete/{id}', 'complete');
        Route::post('/orders-placed/{id}/cancel', 'cancel');

        Route::get('/orders-placed/{id}/overview', 'overview');

        Route::get('/orders-placed/{id}', 'show');
        Route::put('/orders-placed/{id}', 'update');
        Route::delete('/orders-placed/{id}', 'destroy');
    });




    Route::controller(ContactDepartmentsController::class)->group(function () {
        Route::get('/contact/departments', 'index');
        Route::post('/contact/departments', 'store');
    });



    Route::controller(StateController::class)->group(function () {
        Route::get('/states', 'index');
        Route::post('/states', 'store');
        Route::get('/states/countries', 'countries_index');
    });



    Route::controller(RegionController::class)->group(function () {

        Route::get('/regions', 'index');
        Route::post('/regions', 'store');
        Route::get('/regions/countries', 'countries_index');
    });


    Route::controller(CityController::class)->group(function () {

        Route::get('/geo/cities', 'index');
        Route::post('/geo/cities', 'store');
        Route::get('/cities/states', 'states_index');
        Route::get('/cities/countries', 'countries_index');
        Route::get('/states/by-country/{countryId}', 'byCountry');
    });


    Route::prefix('geo')->group(function () {
        Route::get('/countries', [CountryController::class, 'index']);                   // list countries
        Route::get('/regions',   [RegionController::class, 'index']);                    // ?country_id=
        Route::get('/districts', [DistrictController::class, 'index']);                  // list (optional filters)
        Route::post('/districts', [DistrictController::class, 'store']);                 // create
        Route::get('/districts/{district}', [DistrictController::class, 'show']);        // single
        Route::match(['put', 'patch'], '/districts/{district}', [DistrictController::class, 'update']); // update
        Route::delete('/districts/{district}', [DistrictController::class, 'destroy']);  // delete

        Route::get('/regions/by-country/{countryId}', [RegionController::class, 'byCountry']);
        Route::get('/districts/by-region/{regionId}',   [DistrictController::class, 'byRegion']);
    });


    Route::post(RoutePath::for('logout', '/logout'), [AuthenticatedSessionController::class, 'destroy']);
});

Route::post('/login', [UserController::class, 'login']);
