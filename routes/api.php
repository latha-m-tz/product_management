<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\SparepartController;
use App\Http\Controllers\SparepartPurchaseController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\ServiceVCIManagementController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\TrackingTimelineController;
use App\Http\Controllers\TechnicianController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
}); 



Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::view('/forgot-password-form', 'auth.forgot_password');
Route::view('/reset-password-form', 'auth.reset_password');
Route::get('/verify-otp-form', function () {
    return view('auth.verify_otp');
});

Route::prefix('product-types')->group(function () {
    Route::get('/', [ProductTypeController::class, 'index']);
    Route::get('/{id}', [ProductTypeController::class, 'show']);
    Route::post('/', [ProductTypeController::class, 'store']);
    Route::put('/{id}', [ProductTypeController::class, 'update']);
    Route::delete('/{id}', [ProductTypeController::class, 'destroy']);
    Route::post('/link', [ProductTypeController::class, 'linkToProduct']);

});

Route::prefix('product')->group(function() {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
    Route::get('/types/{id}', [ProductController::class, 'getTypesByProduct']);
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
Route::get('/customers/{id}', [CustomerController::class, 'show']);

Route::post('/spareparts', [SparepartController::class, 'store']);
Route::get('/spareparts/{id}/edit', [SparepartController::class, 'edit']);
Route::put('/spareparts/{id}', [SparepartController::class, 'update']);
Route::delete('/spareparts/{id}/del', [SparepartController::class, 'destroy']);
Route::get('/spareparts/get', [SparepartController::class, 'index']);
Route::get('/spareparts/{id}', [SparepartController::class, 'count']);
Route::get('/get-spareparts', [SparepartPurchaseController::class, 'getAvailableSpareparts']);
Route::post('/sparepartNew-purchases', [SparepartPurchaseController::class, 'store']);
Route::get('/sparepart-purchases', [SparepartPurchaseController::class, 'index']);
Route::get('{id}/purchase/edit', [SparepartPurchaseController::class, 'edit']);
Route::put('/purchaseUpdate/{id}', [SparepartPurchaseController::class, 'update']);

Route::get('/available-serials', [SparepartPurchaseController::class, 'availableSerials']);
Route::delete('/sparepart-purchase-items/{id}', [SparepartPurchaseController::class, 'destroy']);
// routes/api.php
Route::get('/sparepart-purchases/view/{id}', [SparepartPurchaseController::class, 'show']);
Route::post('/check-sparepart-serials', [SparepartPurchaseController::class, 'checkSerials']);
Route::get('/get-purchase', [SparepartPurchaseController::class, 'view']);
Route::get('/counts/{series}', [SparepartPurchaseController::class, 'getSeriesSpareparts']);
Route::get('/vci-capacity', [SparepartPurchaseController::class, 'components']);
Route::get('/product-types/product/{id}', [ProductTypeController::class, 'getProduct']);
Route::delete('/purchase-items/{purchaseId}/{itemId}', [SparepartPurchaseController::class, 'deleteItem']);


Route::get('/inventory/serial-numbers', [InventoryController::class, 'serialNumbers']);
Route::prefix('inventory')->group(function () {
    Route::get('/show', [InventoryController::class, 'getAllItems']);
       Route::get('/serialrange/{from_serial}/{to_serial}', [InventoryController::class, 'serialrangeItems']);
Route::put('/serialrange/{from_serial}/{to_serial}', [InventoryController::class, 'updateSerialRange']);
    Route::get('/serialranges', [InventoryController::class, 'serialRanges']);
Route::delete('/serialrange/{from_serial}/{to_serial}', [InventoryController::class, 'deleteSerialRange']);
    Route::get('/', [InventoryController::class, 'index']);
    Route::get('/{id}', [InventoryController::class, 'show']);
    Route::post('/', [InventoryController::class, 'store']);
    Route::put('/{id}', [InventoryController::class, 'update']);
    Route::delete('/{id}', [InventoryController::class, 'destroy']);
    Route::post('/{serial_number}', [InventoryController::class, 'deleteSerial']);
    Route::get('/missing-serials/{from_serial}/{to_serial}', [InventoryController::class, 'getMissingSerials']);

});


//Sales
Route::get('/sales', [SalesController::class, 'index']);        
Route::post('/sales', [SalesController::class, 'store']);       
Route::get('/sales/{id}', [SalesController::class, 'show']);     
Route::put('/sales/{id}', [SalesController::class, 'update']);   
Route::delete('/sales/{id}', [SalesController::class, 'destroy']);
Route::get('/testings', [SalesController::class, 'getTestingData']);
Route::get('/added-serials', [SalesController::class, 'addedSerials']);

//service
Route::get('/service-vci', [ServiceVCIManagementController::class, 'index']);
Route::post('/service-vci', [ServiceVCIManagementController::class, 'store']);
Route::get('/service-vci/{id}', [ServiceVCIManagementController::class, 'show']);
Route::put('/service-vci/{id}', [ServiceVCIManagementController::class, 'update']);
Route::delete('/service-vci/{id}', [ServiceVCIManagementController::class, 'destroy']);
Route::get('/barcode/{barcode}', [BarcodeController::class, 'getProductInfo']);
Route::get('get-serviceserials', [ServiceVCIManagementController::class, 'getAllVCISerialNumbers']);
Route::get('/serviceitems',[ServiceVCIManagementController::class,'getAllServiceItems']);
Route::get('/tracking-timeline/{serial_number}', [TrackingTimelineController::class, 'show']);
Route::delete('/purchase-items/{purchase_id}/{sparepart_id}', [SparepartController::class, 'deleteItem']);
Route::get('/sales/serials/{productId}', [SalesController::class, 'getSaleSerials']);
Route::get('/products/{productId}/serials', [SalesController::class, 'getProductSerials']);
Route::apiResource('technicians', TechnicianController::class);
