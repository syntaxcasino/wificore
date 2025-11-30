<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PublicTenantController;

/*
|--------------------------------------------------------------------------
| Public Tenant Routes
|--------------------------------------------------------------------------
|
| These routes are publicly accessible and used for:
| - Tenant subdomain identification
| - Public package display
| - Subdomain availability checking
|
*/

// Get tenant by subdomain
Route::get('/public/tenant/{subdomain}', [PublicTenantController::class, 'getTenantBySubdomain']);

// Get public packages for a tenant
Route::get('/public/tenant/{subdomain}/packages', [PublicTenantController::class, 'getPublicPackages']);

// Get tenant by current domain
Route::get('/public/tenant-by-domain', [PublicTenantController::class, 'getTenantByDomain']);

// Check subdomain availability
Route::post('/public/subdomain/check', [PublicTenantController::class, 'checkSubdomainAvailability']);
