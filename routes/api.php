<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssembleController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TestingController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



// Assemble routes
Route::prefix('assemble')->group(function() {
    Route::get('/', [AssembleController::class, 'index']);
    Route::get('/{id}', [AssembleController::class, 'show']);
    Route::post('/', [AssembleController::class, 'store']);
    Route::put('/{id}', [AssembleController::class, 'update']);
    Route::delete('/{id}', [AssembleController::class, 'destroy']);
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

Route::prefix('testing')->group(function () {
    Route::get('/', [TestingController::class, 'index']);
    Route::get('/{id}', [TestingController::class, 'show']);
    Route::post('/', [TestingController::class, 'store']);
    Route::put('/{id}', [TestingController::class, 'update']);
    Route::delete('/{id}', [TestingController::class, 'destroy']);
});