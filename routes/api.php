<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\SparepartController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\ServiceVCIManagementController;
use App\Http\Controllers\BarcodeController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
}); 




Route::prefix('product-types')->group(function () {
    Route::get('/', [ProductTypeController::class, 'index']);
    Route::get('/{id}', [ProductTypeController::class, 'show']);
    Route::post('/', [ProductTypeController::class, 'store']);
    Route::put('/{id}', [ProductTypeController::class, 'update']);
    Route::delete('/{id}', [ProductTypeController::class, 'destroy']);
});

Route::prefix('product')->group(function() {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
});




Route::post('/vendors/new', [VendorController::class, 'Vendorstore']);

Route::get('/{id}/edit', [VendorController::class, 'VendorEdit']); 
Route::put('/{id}', [VendorController::class, 'VendorUpdate']);  
Route::get('/vendorsget', [VendorController::class, 'VendorList']); 
Route::get('/vendors/get/{id}', [VendorController::class, 'show']);
Route::delete('/vendors/{id}', [VendorController::class, 'destroy']);


Route::post('/customers', [CustomerController::class, 'store']);
Route::get('customers/{id}/edit', [CustomerController::class, 'edit']);
Route::put('customers/{id}', [CustomerController::class, 'update']);
Route::delete('customers/del/{id}', [CustomerController::class, 'destroy']);
Route::get('/customers/get', [CustomerController::class, 'index']);

Route::get('/inventory/serial-numbers', [InventoryController::class, 'serialNumbers']);
Route::prefix('inventory')->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
    Route::get('/{id}', [InventoryController::class, 'show']);
    Route::post('/', [InventoryController::class, 'store']);
    Route::put('/{id}', [InventoryController::class, 'update']);
    Route::delete('/{id}', [InventoryController::class, 'destroy']);
});


//Sales
Route::get('/sales', [SalesController::class, 'index']);        
Route::post('/sales', [SalesController::class, 'store']);       
Route::get('/sales/{id}', [SalesController::class, 'show']);     
Route::put('/sales/{id}', [SalesController::class, 'update']);   
Route::delete('/sales/{id}', [SalesController::class, 'destroy']);
Route::get('/testings', [SalesController::class, 'getTestingData']);


//service
Route::get('/service-vci', [ServiceVCIManagementController::class, 'index']);
Route::post('/service-vci', [ServiceVCIManagementController::class, 'store']);
Route::get('/service-vci/{id}', [ServiceVCIManagementController::class, 'show']);
Route::put('/service-vci/{id}', [ServiceVCIManagementController::class, 'update']);
Route::delete('/service-vci/{id}', [ServiceVCIManagementController::class, 'destroy']);
Route::get('/barcode/{barcode}', [BarcodeController::class, 'getProductInfo']);