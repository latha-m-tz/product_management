<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssembleController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TestingController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\SparepartController;
use App\Http\Controllers\CustomerController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



// Assemble routes


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