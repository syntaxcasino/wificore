<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Private user channel - only the user can listen to their own channel
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Tenant-specific router updates channel
Broadcast::channel('tenant.{tenantId}.router-updates', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    // Users can only access their tenant's router updates
    return $user->tenant_id === $tenantId;
});

// Private router status channel - requires authentication
Broadcast::channel('router-status', function ($user) {
    // User must be authenticated via Sanctum
    return $user !== null;
});

// Private routers channel - requires authentication
Broadcast::channel('routers', function ($user) {
    // User must be authenticated via Sanctum
    return $user !== null;
});

// Private presence channel for online users
Broadcast::channel('online', function ($user) {
    if ($user) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
    return false;
});

// Tenant-specific admin notifications channel
Broadcast::channel('tenant.{tenantId}.admin-notifications', function ($user, $tenantId) {
    // System admins can access all tenants
    if ($user->isSystemAdmin()) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role];
    }
    // Tenant admins can only access their own tenant
    return $user->isAdmin() && $user->tenant_id === $tenantId;
});

// Router provisioning progress channel - only admins can listen
Broadcast::channel('router-provisioning.{routerId}', function ($user, $routerId) {
    // Only admins can listen to router provisioning progress
    return $user !== null && $user->isAdmin();
});

// Tenant-specific dashboard stats channel
Broadcast::channel('tenant.{tenantId}.dashboard-stats', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    // Users can only access their tenant's stats
    return $user->tenant_id === $tenantId;
});

// Tenant-specific payments channel
Broadcast::channel('tenant.{tenantId}.payments', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    // Admins can only access their tenant's payments
    return $user->isAdmin() && $user->tenant_id === $tenantId;
});

// Tenant-specific hotspot users channel
Broadcast::channel('tenant.{tenantId}.hotspot-users', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    // Admins can only access their tenant's hotspot users
    return $user->isAdmin() && $user->tenant_id === $tenantId;
});

// Tenant-specific packages channel
Broadcast::channel('tenant.{tenantId}.packages', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    // Users can only access their tenant's packages
    return $user->tenant_id === $tenantId;
});

// Tenant-specific security alerts channel (suspension/unsuspension notifications)
Broadcast::channel('tenant.{tenantId}.security-alerts', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    // Tenant admins can only access their tenant's security alerts
    return $user->isAdmin() && $user->tenant_id === $tenantId;
});

// System admin security alerts channel (all platform security events)
Broadcast::channel('system.admin.security-alerts', function ($user) {
    // Only system admins can access this channel
    return $user->isSystemAdmin();
});

// =============================================================================
// WebSocket Real-Time Notification Channels
// =============================================================================

// Public tenant channel - all users in the tenant can listen
Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    // System admins can access all tenant channels
    if ($user->role === 'system_admin') {
        return true;
    }
    // Users can only access their own tenant's channel
    return $user->tenant_id === $tenantId;
});

// Private user channel - only the user can listen
Broadcast::channel('user.{userId}', function ($user, $userId) {
    // User can only listen to their own private channel
    return $user->id === $userId;
});

// System admin channel - only system admins
Broadcast::channel('system.admin', function ($user) {
    // Only system admins can access this channel
    return $user->role === 'system_admin';
});