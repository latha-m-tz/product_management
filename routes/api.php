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
use App\Http\Controllers\ServiceVCIDeliveryController;
// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
}); 
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::get('/login', [AuthController::class, 'getLogin']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/users', [AuthController::class, 'getUsers']);

Route::middleware(['jwt.auth'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/register', [AuthController::class, 'getUsers']);
    Route::get('/users-protected', [AuthController::class, 'index']);
});

Route::view('/forgot-password-form', 'auth.forgot_password');
Route::view('/reset-password-form', 'auth.reset_password');
Route::get('/verify-otp-form', function () {
    return view('auth.verify_otp');
});
// Route::prefix('product-types')->group(function () {
//     Route::get('/', [ProductTypeController::class, 'index']);
//     Route::get('/{id}', [ProductTypeController::class, 'show']);
//     Route::post('/', [ProductTypeController::class, 'store']);
//     Route::put('/{id}', [ProductTypeController::class, 'update']);
//     Route::delete('/{id}', [ProductTypeController::class, 'destroy']);
//     Route::post('/link', [ProductTypeController::class, 'linkToProduct']);
// });

Route::prefix('product')->middleware(['jwt.auth'])->group(function() {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
    Route::get('/types/{id}', [ProductController::class, 'getTypesByProduct']);
});




Route::post('/vendors/new', [VendorController::class, 'Vendorstore'])->middleware('jwt.auth');
Route::get('/{id}/edit', [VendorController::class, 'VendorEdit'])->middleware('jwt.auth');
Route::put('/{id}', [VendorController::class, 'VendorUpdate']);
Route::get('/vendorsget', [VendorController::class, 'VendorList'])->middleware('jwt.auth');
Route::get('/vendors/get/{id}', [VendorController::class, 'show']);
Route::delete('/vendors/{id}', [VendorController::class, 'destroy'])->middleware('jwt.auth');



Route::post('/customers', [CustomerController::class, 'store'])->middleware('jwt.auth');
Route::get('customers/{id}/edit', [CustomerController::class, 'edit'])->middleware('jwt.auth');
Route::put('customers/{id}', [CustomerController::class, 'update'])->middleware('jwt.auth');
Route::delete('customers/del/{id}', [CustomerController::class, 'destroy'])->middleware('jwt.auth');
Route::get('/customers/get', [CustomerController::class, 'index'])->middleware('jwt.auth');
Route::get('/customers/{id}', [CustomerController::class, 'show'])->middleware('jwt.auth');


Route::post('/spareparts', [SparepartController::class, 'store'])->middleware('jwt.auth');
Route::get('/spareparts/{id}/edit', [SparepartController::class, 'edit'])->middleware('jwt.auth');
Route::put('/spareparts/{id}', [SparepartController::class, 'update'])->middleware('jwt.auth');
Route::delete('/spareparts/{id}/del', [SparepartController::class, 'destroy'])->middleware('jwt.auth');
Route::get('/spareparts/get', [SparepartController::class, 'index'])->middleware('jwt.auth');
Route::get('/spareparts/{id}', [SparepartController::class, 'count'])->middleware('jwt.auth');
Route::get('/get-spareparts', [SparepartPurchaseController::class, 'getAvailableSpareparts'])->middleware('jwt.auth');
Route::post('/sparepartNew-purchases', [SparepartPurchaseController::class, 'store'])->middleware('jwt.auth');
Route::get('/sparepart-purchases', [SparepartPurchaseController::class, 'index'])->middleware('jwt.auth');
Route::get('{id}/purchase/edit', [SparepartPurchaseController::class, 'edit'])->middleware('jwt.auth');
Route::put('/purchaseUpdate/{id}', [SparepartPurchaseController::class, 'update'])->middleware('jwt.auth');

Route::get('/available-serials', [SparepartPurchaseController::class, 'availableSerials'])->middleware('jwt.auth');
Route::delete('/sparepart-purchase-items/{id}', [SparepartPurchaseController::class, 'destroy'])->middleware('jwt.auth');
// routes/api.php
Route::get('/sparepart-purchases/view/{id}', [SparepartPurchaseController::class, 'show'])->middleware('jwt.auth');
Route::post('/check-sparepart-serials', [SparepartPurchaseController::class, 'checkSerials'])->middleware('jwt.auth');
Route::get('/get-purchase', [SparepartPurchaseController::class, 'view'])->middleware('jwt.auth');
// Route::get('/counts/{series}', [SparepartPurchaseController::class, 'getSeriesSpareparts']);
Route::get('/products/series/{series}', [SparepartPurchaseController::class, 'getSeriesSpareparts'])->middleware('jwt.auth');
Route::get('/vci-capacity', [SparepartPurchaseController::class, 'components'])->middleware('jwt.auth');
Route::get('/product-types/product/{id}', [ProductTypeController::class, 'getProduct'])->middleware('jwt.auth');
Route::delete('/purchase-items/{purchaseId}/{itemId}', [SparepartPurchaseController::class, 'deleteItem'])->middleware('jwt.auth');


Route::get('/inventory/serial-numbers', [InventoryController::class, 'serialNumbers'])->middleware('jwt.auth');
Route::prefix('inventory')->middleware(['jwt.auth'])->group(function(){
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
    // Route::post('/{serial_number}', [InventoryController::class, 'deleteSerial']);
    Route::get('/missing-serials/{from_serial}/{to_serial}', [InventoryController::class, 'getMissingSerials']);
});

Route::post('/check-serials-purchased', [InventoryController::class, 'checkSerialsPurchased']) ->middleware('jwt.auth');
Route::get('/inventory/serials/active', [InventoryController::class, 'getAllActiveSerials'])->middleware('jwt.auth');
Route::delete('/inventory/delete/{serial_no}', [InventoryController::class, 'deleteSerial'])->middleware('jwt.auth');



//Sales
Route::get('/sales', [SalesController::class, 'index'])->middleware('jwt.auth');        
Route::post('/sales', [SalesController::class, 'store'])->middleware('jwt.auth');      
Route::get('/sales/{id}', [SalesController::class, 'show'])->middleware('jwt.auth');    
Route::put('/sales/{id}', [SalesController::class, 'update'])->middleware('jwt.auth');
Route::delete('/sales/{id}', [SalesController::class, 'destroy'])->middleware('jwt.auth');
Route::get('/testings', [SalesController::class, 'getTestingData'])->middleware('jwt.auth');
Route::get('/added-serials', [SalesController::class, 'addedSerials'])->middleware('jwt.auth');

//service
Route::get('/service-vci', [ServiceVCIManagementController::class, 'index'])->middleware('jwt.auth');
Route::post('/service-vci', [ServiceVCIManagementController::class, 'store'])->middleware('jwt.auth');
Route::get('/service-vci/{id}', [ServiceVCIManagementController::class, 'show'])->middleware('jwt.auth');
Route::put('/service-vci/{id}', [ServiceVCIManagementController::class, 'update'])->middleware('jwt.auth');
Route::delete('/service-vci/{id}', [ServiceVCIManagementController::class, 'destroy'])->middleware('jwt.auth');
Route::get('/barcode/{barcode}', [BarcodeController::class, 'getProductInfo'])->middleware('jwt.auth');
Route::get('get-serviceserials', [ServiceVCIManagementController::class, 'getAllVCISerialNumbers'])->middleware('jwt.auth');
Route::get('/serviceitems',[ServiceVCIManagementController::class,'getAllServiceItems'])->middleware('jwt.auth');
Route::get('/tracking-timeline/{serial_number}', [TrackingTimelineController::class, 'show'])->middleware('jwt.auth');
Route::delete('/purchase-items/{purchase_id}/{sparepart_id}', [SparepartController::class, 'deleteItem'])->middleware('jwt.auth');
Route::get('/sales/serials/{productId}', [SalesController::class, 'getSaleSerials'])->middleware('jwt.auth');
Route::get('/products/{productId}/serials', [SalesController::class, 'getProductSerials'])->middleware('jwt.auth');
Route::apiResource('technicians', TechnicianController::class)->middleware('jwt.auth');




// Route::prefix('service-deliveries')->group(function () {
//     Route::get('/', [ServiceVCIDeliveryController::class, 'index']);
//     Route::get('/eligible', [ServiceVCIDeliveryController::class, 'eligibleItems']);
//     Route::post('/', [ServiceVCIDeliveryController::class, 'store']);
//     Route::get('/{id}', [ServiceVCIDeliveryController::class, 'show']);
//     Route::put('/{id}', [ServiceVCIDeliveryController::class, 'update']);
//     Route::delete('/{id}', [ServiceVCIDeliveryController::class, 'destroy']);
// });
