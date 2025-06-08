<?php

use App\Models\SecurityRole;
use Illuminate\Http\Request;
use Laravel\Fortify\RoutePath;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductTypesController;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\ProductBrandsController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\ProductDepartmentsController;
use App\Http\Controllers\ProductManufactureController;
use App\Http\Controllers\ProductSubDepartmentController;
use App\Http\Controllers\ProductSubSubDepartmentController;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
 

Route::group(['middleware' => 'auth:sanctum'], function () {


   Route::post('/users', [UserController::class, 'store']);

   Route::get('/users-with-roles', [UserController::class, 'getUsersWithRoles']);
 

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


    Route::controller(ProductDepartmentsController::class)->group(function () {
       Route::get('/productdepartment', 'index');
       Route::post('/productdepartment', 'store');
       Route::get('/sub-departments/{departmentId}' ,'getSubDepartments');
    });



    Route::controller(ProductBrandsController::class)->group(function () {
          
         Route::get('/productbrands', 'index');
         Route::post('/productbrands', 'store');
  

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
         Route::post('/productsubdepartment', 'store');

     });


     Route::controller(ProductSubSubDepartmentController::class)->group(function () {
 
         Route::get('/sub-sub-departments', 'index');
         Route::post('/sub-sub-departments', 'store');
       
     });
     
     Route::get('/roles/{id}/permissions', [RolePermissionController::class, 'getRolePermissions']);
     Route::post('/roles/{id}/permissions', [RolePermissionController::class, 'updateRolePermissions']);

    Route::controller(RolePermissionController::class)->group(function () {
            Route::post('/roles',  'storeRole');
            Route::get('/roles', function () {
                             return SecurityRole::select('id', 'name','created_at')->get();
                           });
            Route::post('/permissions',  'storePermission');
            Route::post('/assign-role',   'assignRole');     
      });


   Route::post(RoutePath::for('logout', '/logout'), [AuthenticatedSessionController::class, 'destroy']);
});

Route::post('/login', [AuthenticatedController::class, 'store']);

