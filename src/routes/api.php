<?php

use App\Models\SecurityRole;
use Illuminate\Http\Request;
use Laravel\Fortify\RoutePath;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CityController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\OrdersPlacedController;
use App\Http\Controllers\ProductTypesController;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\ProductBrandsController;
use App\Http\Controllers\ProductImagesController;
use App\Http\Controllers\ProductMasterController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\ProductsBarcodesController;
use App\Http\Controllers\ProductDepartmentsController;
use App\Http\Controllers\ProductManufactureController;
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



    Route::controller(ProductDepartmentsController::class)->group(function () {
       Route::get('/productdepartment', 'index');
       Route::post('/productdepartment', 'store');
       Route::get('/sub-departments/{departmentId}' ,'getSubDepartments');
       Route::get('/sub-sub-departments/{subDepartmentId}' ,'bySubDepartment');
       Route::delete('/productdepartment/{productdepartment}', 'destroy');

      
    });



    Route::controller(ProductBrandsController::class)->group(function () {
          
         Route::get('/productbrands', 'index');
         Route::post('/productbrands', 'store');
  

    });


    Route::controller(ProductsBarcodesController::class)->group(function () {
          Route::get('/productmaster/{id}/barcodes','getProductBarcodes');
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
     
         Route::post('/product-specifications', 'store');
     
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
            Route::post('/orders-placed', 'store');
            Route::get('/orders-placed/{id}', 'show');
            Route::put('/orders-placed/{id}', 'update');
            Route::delete('/orders-placed/{id}', 'destroy');

      });




        Route::controller(StateController::class)->group(function () {
            Route::get('/states', 'index');
            Route::post('/states', 'store');
            Route::get('/states/countries', 'countries_index');

      });


        Route::controller(CityController::class)->group(function () {

               Route::get('/cities',  'index');
               Route::post('/cities',  'store');
               Route::get('/cities/states',  'states_index');
               Route::get('/cities/countries',  'countries_index');
               Route::get('/states/by-country/{countryId}','byCountry');


        });
  

   Route::post(RoutePath::for('logout', '/logout'), [AuthenticatedSessionController::class, 'destroy']);
});

Route::post('/login', [UserController::class, 'login']);

