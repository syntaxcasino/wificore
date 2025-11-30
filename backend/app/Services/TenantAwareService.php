<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Package;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Payment;
use App\Models\HotspotUser;

/**
 * Base service class that enforces tenant isolation
 * All services that handle tenant-specific data should extend this class
 */
abstract class TenantAwareService
{
    /**
     * Get tenant ID from authenticated user
     * 
     * @throws \Exception if user not authenticated or is system admin
     */
    protected function getTenantId(): string
    {
        $user = auth()->user();
        
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        
        // System admins should not perform tenant-specific operations
        // They should use explicit tenant ID parameters
        if ($user->role === 'system_admin') {
            throw new \Exception('System admin must specify tenant explicitly');
        }
        
        if (!$user->tenant_id) {
            throw new \Exception('User does not belong to any tenant');
        }
        
        return $user->tenant_id;
    }
    
    /**
     * Validate that all provided models belong to the specified tenant
     * 
     * @param string $tenantId
     * @param mixed ...$models
     * @throws \Exception if any model doesn't belong to tenant
     */
    protected function validateTenantOwnership(string $tenantId, ...$models): void
    {
        foreach ($models as $model) {
            if (!$model) {
                continue;
            }
            
            // Skip if model doesn't have tenant_id property
            if (!property_exists($model, 'tenant_id')) {
                continue;
            }
            
            if ($model->tenant_id !== $tenantId) {
                $modelClass = class_basename($model);
                throw new \Exception(
                    "{$modelClass} (ID: {$model->id}) does not belong to tenant {$tenantId}. " .
                    "This operation is not allowed for security reasons."
                );
            }
        }
    }
    
    /**
     * Validate router belongs to tenant
     * 
     * @param Router $router
     * @param string $tenantId
     * @throws \Exception if router doesn't belong to tenant
     */
    protected function validateRouter(Router $router, string $tenantId): void
    {
        if ($router->tenant_id !== $tenantId) {
            throw new \Exception(
                "Router '{$router->name}' (ID: {$router->id}) does not belong to this tenant. " .
                "Cannot perform operations on routers from other tenants."
            );
        }
    }
    
    /**
     * Validate package belongs to tenant
     * 
     * @param Package $package
     * @param string $tenantId
     * @throws \Exception if package doesn't belong to tenant
     */
    protected function validatePackage(Package $package, string $tenantId): void
    {
        if ($package->tenant_id !== $tenantId) {
            throw new \Exception(
                "Package '{$package->name}' (ID: {$package->id}) does not belong to this tenant. " .
                "Cannot use packages from other tenants."
            );
        }
    }
    
    /**
     * Validate user belongs to tenant
     * 
     * @param User $user
     * @param string $tenantId
     * @throws \Exception if user doesn't belong to tenant
     */
    protected function validateUser(User $user, string $tenantId): void
    {
        if ($user->tenant_id !== $tenantId) {
            throw new \Exception(
                "User '{$user->name}' (ID: {$user->id}) does not belong to this tenant. " .
                "Cannot perform operations on users from other tenants."
            );
        }
    }
    
    /**
     * Validate voucher belongs to tenant
     * 
     * @param Voucher $voucher
     * @param string $tenantId
     * @throws \Exception if voucher doesn't belong to tenant
     */
    protected function validateVoucher(Voucher $voucher, string $tenantId): void
    {
        if ($voucher->tenant_id !== $tenantId) {
            throw new \Exception(
                "Voucher '{$voucher->code}' does not belong to this tenant. " .
                "Cannot use vouchers from other tenants."
            );
        }
    }
    
    /**
     * Validate payment belongs to tenant
     * 
     * @param Payment $payment
     * @param string $tenantId
     * @throws \Exception if payment doesn't belong to tenant
     */
    protected function validatePayment(Payment $payment, string $tenantId): void
    {
        if ($payment->tenant_id !== $tenantId) {
            throw new \Exception(
                "Payment (ID: {$payment->id}) does not belong to this tenant. " .
                "Cannot access payments from other tenants."
            );
        }
    }
    
    /**
     * Validate hotspot user belongs to tenant
     * 
     * @param HotspotUser $hotspotUser
     * @param string $tenantId
     * @throws \Exception if hotspot user doesn't belong to tenant
     */
    protected function validateHotspotUser(HotspotUser $hotspotUser, string $tenantId): void
    {
        if ($hotspotUser->tenant_id !== $tenantId) {
            throw new \Exception(
                "Hotspot user '{$hotspotUser->username}' does not belong to this tenant. " .
                "Cannot manage hotspot users from other tenants."
            );
        }
    }
    
    /**
     * Ensure all models in a collection belong to the same tenant
     * 
     * @param \Illuminate\Support\Collection $collection
     * @param string $tenantId
     * @throws \Exception if any model doesn't belong to tenant
     */
    protected function validateCollection($collection, string $tenantId): void
    {
        foreach ($collection as $model) {
            $this->validateTenantOwnership($tenantId, $model);
        }
    }
    
    /**
     * Log tenant-aware operation
     * 
     * @param string $action
     * @param array $details
     * @param string $tenantId
     */
    protected function logTenantOperation(string $action, array $details, string $tenantId): void
    {
        \Log::info("Tenant Operation: {$action}", [
            'tenant_id' => $tenantId,
            'user_id' => auth()->id(),
            'details' => $details,
            'timestamp' => now()->toDateTimeString()
        ]);
    }
    
    /**
     * Get router for tenant with validation
     * 
     * @param string $routerId
     * @param string|null $tenantId
     * @return Router
     * @throws \Exception if router not found or doesn't belong to tenant
     */
    protected function getRouterForTenant(string $routerId, ?string $tenantId = null): Router
    {
        $tenantId = $tenantId ?? $this->getTenantId();
        
        $router = Router::where('id', $routerId)
            ->where('tenant_id', $tenantId)
            ->first();
            
        if (!$router) {
            throw new \Exception(
                "Router not found or does not belong to this tenant"
            );
        }
        
        return $router;
    }
    
    /**
     * Get package for tenant with validation
     * 
     * @param string $packageId
     * @param string|null $tenantId
     * @return Package
     * @throws \Exception if package not found or doesn't belong to tenant
     */
    protected function getPackageForTenant(string $packageId, ?string $tenantId = null): Package
    {
        $tenantId = $tenantId ?? $this->getTenantId();
        
        $package = Package::where('id', $packageId)
            ->where('tenant_id', $tenantId)
            ->first();
            
        if (!$package) {
            throw new \Exception(
                "Package not found or does not belong to this tenant"
            );
        }
        
        return $package;
    }
}
