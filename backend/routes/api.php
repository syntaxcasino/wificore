<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\LogController;



Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::get('/packages', [PackageController::class, 'index']);
Route::post('/payments/initiate', [PaymentController::class, 'initiateSTK']);
Route::post('/mpesa/callback', [PaymentController::class, 'callback']);
Route::get('/logs', [LogController::class, 'index']);