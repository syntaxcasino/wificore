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
    return (string) $user->id === (string) $id;
});

// Tenant-specific router updates channel
Broadcast::channel('tenant.{tenantId}.router-updates', function ($user, $tenantId) {
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only users belonging to this specific tenant can access
    return (string) $user->tenant_id === (string) $tenantId;
});

// Tenant-specific routers channel - for router interface discovery and status events
Broadcast::channel('tenant.{tenantId}.routers', function ($user, $tenantId) {
    // SECURITY: Only users belonging to this specific tenant can access
    return (string) $user->tenant_id === (string) $tenantId;
});

// Tenant-specific VPN channel - for VPN connectivity verification events
Broadcast::channel('tenant.{tenantId}.vpn', function ($user, $tenantId) {
    // SECURITY: Only users belonging to this specific tenant can access
    return (string) $user->tenant_id === (string) $tenantId;
});

// Private router status channel - requires authentication and tenant isolation
Broadcast::channel('router-status', function ($user) {
    // SECURITY: This channel should be tenant-specific, not global
    // Deprecated: Use tenant.{tenantId}.router-status instead
    return false; // Disabled for security
});

// Private routers channel - requires authentication and tenant isolation
Broadcast::channel('routers', function ($user) {
    // SECURITY: This channel should be tenant-specific, not global
    // Deprecated: Use tenant.{tenantId}.routers instead
    return false; // Disabled for security
});

// Private presence channel for online users (DEPRECATED - use tenant-specific)
Broadcast::channel('online', function ($user) {
    // SECURITY: This global channel is deprecated for security
    // Use tenant.{tenantId}.online or system.online instead
    return false; // Disabled for security
});

// Tenant-specific online presence channel
Broadcast::channel('tenant.{tenantId}.online', function ($user, $tenantId) {
    // SECURITY: Only users in this tenant can see who's online in their tenant
    if ((string) $user->tenant_id === (string) $tenantId) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
    return false;
});

// System admin online presence channel
Broadcast::channel('system.online', function ($user) {
    // SECURITY: Only system admins can see who's online at system level
    if ($user->role === 'system_admin' && $user->tenant_id === null) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
    return false;
});

// Tenant-specific admin notifications channel
Broadcast::channel('tenant.{tenantId}.admin-notifications', function ($user, $tenantId) {
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only tenant admins belonging to this specific tenant can access
    return $user->isAdmin() && (string) $user->tenant_id === (string) $tenantId;
});

// Router provisioning progress channel - only admins can listen
Broadcast::channel('router-provisioning.{routerId}', function ($user, $routerId) {
    // Only admins can listen to router provisioning progress
    return $user !== null && $user->isAdmin();
});

// Tenant-specific dashboard stats channel
Broadcast::channel('tenant.{tenantId}.dashboard-stats', function ($user, $tenantId) {
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only users belonging to this specific tenant can access
    return (string) $user->tenant_id === (string) $tenantId;
});

// Tenant-specific payments channel
Broadcast::channel('tenant.{tenantId}.payments', function ($user, $tenantId) {
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only tenant admins belonging to this specific tenant can access
    return $user->isAdmin() && (string) $user->tenant_id === (string) $tenantId;
});

// Tenant-specific hotspot users channel
Broadcast::channel('tenant.{tenantId}.hotspot-users', function ($user, $tenantId) {
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only tenant admins belonging to this specific tenant can access
    return $user->isAdmin() && (string) $user->tenant_id === (string) $tenantId;
});

// Tenant-specific packages channel
Broadcast::channel('tenant.{tenantId}.packages', function ($user, $tenantId) {
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only users belonging to this specific tenant can access
    return (string) $user->tenant_id === (string) $tenantId;
});

// Tenant-specific security alerts channel (suspension/unsuspension notifications)
Broadcast::channel('tenant.{tenantId}.security-alerts', function ($user, $tenantId) {
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only tenant admins belonging to this specific tenant can access
    return $user->isAdmin() && (string) $user->tenant_id === (string) $tenantId;
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
    // SECURITY: System admins should NOT access tenant channels (data isolation)
    // Only users belonging to this specific tenant can access
    return (string) $user->tenant_id === (string) $tenantId;
});

// Private user channel - only the user can listen
Broadcast::channel('user.{userId}', function ($user, $userId) {
    // User can only listen to their own private channel
    return (string) $user->id === (string) $userId;
});

// System admin channel - only system admins
Broadcast::channel('system.admin', function ($user) {
    // SECURITY: Only system admins (tenant_id = NULL) can access
    return $user->role === 'system_admin' && $user->tenant_id === null;
});

// System admin dashboard stats channel
Broadcast::channel('system.dashboard-stats', function ($user) {
    // SECURITY: Only system admins can access system-level stats
    return $user->role === 'system_admin' && $user->tenant_id === null;
});

// System admin tenants channel (tenant management)
Broadcast::channel('system.tenants', function ($user) {
    // SECURITY: Only system admins can access tenant management events
    return $user->role === 'system_admin' && $user->tenant_id === null;
});

// System admin metrics channel (system-wide metrics)
Broadcast::channel('system.metrics', function ($user) {
    // SECURITY: Only system admins can access system metrics
    return $user->role === 'system_admin' && $user->tenant_id === null;
});

// System admin queue stats channel
Broadcast::channel('system.queue-stats', function ($user) {
    // SECURITY: Only system admins can access queue statistics
    return $user->role === 'system_admin' && $user->tenant_id === null;
});