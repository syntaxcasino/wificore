<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;
use App\Models\Router;

/*
|--------------------------------------------------------------------------
| Broadcast Channels - FIXED VERSION (Tenant-Aware & Secure)
|--------------------------------------------------------------------------
|
| All channels now include tenant validation to prevent cross-tenant data leaks.
| Channel naming convention: tenant.{tenantId}.{channelName}
|
*/

// ============================================================================
// TENANT-SPECIFIC CHANNELS
// ============================================================================

/**
 * Admin notifications channel - Tenant-specific
 * Only admins from the specified tenant can listen
 */
Broadcast::channel('tenant.{tenantId}.admin-notifications', function ($user, $tenantId) {
    // System admins can access all tenants
    if ($user->isSystemAdmin()) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ];
    }
    
    // Tenant admins can only access their own tenant
    if ($user->isAdmin() && $user->tenant_id === $tenantId) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id,
        ];
    }
    
    return false;
});

/**
 * Dashboard stats channel - Tenant-specific
 * Only users from the specified tenant can listen
 */
Broadcast::channel('tenant.{tenantId}.dashboard-stats', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    
    // Users can only access their tenant's stats
    return $user->tenant_id === $tenantId;
});

/**
 * Payments channel - Tenant-specific
 * Only admins from the specified tenant can listen
 */
Broadcast::channel('tenant.{tenantId}.payments', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    
    // Tenant admins can only access their tenant's payments
    return $user->isAdmin() && $user->tenant_id === $tenantId;
});

/**
 * Router updates channel - Tenant-specific
 * Only users from the specified tenant can listen
 */
Broadcast::channel('tenant.{tenantId}.router-updates', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    
    // Users can only access their tenant's router updates
    return $user->tenant_id === $tenantId;
});

/**
 * Hotspot users channel - Tenant-specific
 * Only admins from the specified tenant can listen
 */
Broadcast::channel('tenant.{tenantId}.hotspot-users', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    
    // Admins can only access their tenant's hotspot users
    return $user->isAdmin() && $user->tenant_id === $tenantId;
});

/**
 * Packages channel - Tenant-specific
 * Only users from the specified tenant can listen
 */
Broadcast::channel('tenant.{tenantId}.packages', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    
    // Users can only access their tenant's packages
    return $user->tenant_id === $tenantId;
});

/**
 * Router provisioning progress - Tenant-specific
 * Only admins from the specified tenant can listen
 * Also validates router ownership
 */
Broadcast::channel('tenant.{tenantId}.router-provisioning.{routerId}', function ($user, $tenantId, $routerId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    
    // Verify user's tenant matches
    if ($user->tenant_id !== $tenantId) {
        return false;
    }
    
    // Verify user is admin
    if (!$user->isAdmin()) {
        return false;
    }
    
    // Verify router belongs to this tenant
    $router = Router::find($routerId);
    if (!$router || $router->tenant_id !== $tenantId) {
        return false;
    }
    
    return [
        'id' => $user->id,
        'name' => $user->name,
        'tenant_id' => $user->tenant_id,
    ];
});

// ============================================================================
// USER-SPECIFIC CHANNELS (Already Secure)
// ============================================================================

/**
 * Private user channel - User can only listen to their own channel
 * This was already secure, no changes needed
 */
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user->id === $id;
});

// ============================================================================
// SYSTEM-WIDE CHANNELS (System Admin Only)
// ============================================================================

/**
 * System health monitoring - System admin only
 */
Broadcast::channel('system.health', function ($user) {
    return $user->isSystemAdmin() ? [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $user->role,
    ] : false;
});

/**
 * System metrics - System admin only
 */
Broadcast::channel('system.metrics', function ($user) {
    return $user->isSystemAdmin() ? [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $user->role,
    ] : false;
});

/**
 * System activity logs - System admin only
 */
Broadcast::channel('system.activity', function ($user) {
    return $user->isSystemAdmin() ? [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $user->role,
    ] : false;
});

// ============================================================================
// PRESENCE CHANNELS
// ============================================================================

/**
 * Online presence - Tenant-specific
 * Users can see who's online in their tenant
 */
Broadcast::channel('tenant.{tenantId}.online', function ($user, $tenantId) {
    // System admins can see all
    if ($user->isSystemAdmin()) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'tenant_id' => 'system',
        ];
    }
    
    // Users can only see their tenant's online users
    if ($user->tenant_id === $tenantId) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id,
        ];
    }
    
    return false;
});
