<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\RouterController;
use App\Http\Controllers\Api\ProvisioningController;

Route::get('/packages', [PackageController::class, 'index']);
Route::post('/packages', [PackageController::class, 'store']);
Route::post('/payments/initiate', [PaymentController::class, 'initiateSTK']);
Route::post('/mpesa/callback', [PaymentController::class, 'callback']);
Route::get('/logs', [LogController::class, 'index']);
Route::get('/routers', [RouterController::class, 'index']);
Route::post('/routers', [RouterController::class, 'store']);
Route::get('/routers/{router}', [RouterController::class, 'show']);
Route::put('/routers/{router}', [RouterController::class, 'update']);
Route::delete('/routers/{router}', [RouterController::class, 'destroy']);
Route::get('/routers/{router}/status', [RouterController::class, 'status']);
Route::post('/routers/{router}/configure', [RouterController::class, 'configure']);
Route::post('/routers/{router}/generate-service-config', [RouterController::class, 'generateConfigs']);
Route::post('/routers/{router}/update-firmware', [RouterController::class, 'updateFirmware']);
Route::get('/routers/{router}/verify-connectivity', [RouterController::class, 'verifyConnectivity']);
Route::post('/routers/{router}/apply-configs', [RouterController::class, 'applyConfigs']); // Added missing route
Route::prefix('provisioning')->group(function () {
    Route::post('/configs', [ProvisioningController::class, 'saveConfigs']);
    Route::get('/configs', [ProvisioningController::class, 'getConfigs']);
    Route::post('/interfaces', [ProvisioningController::class, 'fetchInterfaces']);
    Route::post('/apply', [ProvisioningController::class, 'applyConfigs']);
});
Route::apiResource('router-configs', ProvisioningController::class)->only(['index', 'store', 'update']);