<?php

namespace App\Services;

use Dapphp\Radius\Radius;

class RadiusService extends TenantAwareService
{
    protected $radius;

    public function __construct()
    {
        $this->radius = new Radius();
        
        // Add server configuration
        $this->radius->setServer(
            env('RADIUS_SERVER_HOST', 'wificore-freeradius')
        );
        
        $this->radius->setSecret(
            env('RADIUS_SECRET', 'testing123')
        );
        
        $this->radius->setAuthenticationPort(
            (int) env('RADIUS_SERVER_PORT', 1812)
        );
        
        // Set NAS identifier
        $this->radius->setNasIpAddress('127.0.0.1');
    }

    /**
     * Authenticate user via RADIUS
     * 
     * NOTE: Schema lookup is handled automatically by PostgreSQL functions.
     * No need to set search_path - functions determine correct schema from username.
     * This provides high performance without connection state changes.
     */
    public function authenticate(string $username, string $password): bool
    {
        try {
            \Log::info("RADIUS: Attempting authentication for user: {$username}");
            
            // PostgreSQL functions automatically determine correct tenant schema
            $result = $this->radius->accessRequest($username, $password);
            
            if ($result === true) {
                \Log::info("RADIUS: Authentication successful for user: {$username}");
                return true;
            } else {
                \Log::warning("RADIUS: Authentication failed for user: {$username}");
                return false;
            }
        } catch (\Exception $e) {
            \Log::error("RADIUS error for user {$username}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new user in RADIUS (tenant-aware)
     * 
     * IMPORTANT: This method uses the current tenant context (search_path).
     * Ensure tenant context is set before calling this method.
     * 
     * @param string $username
     * @param string $password
     * @param string|null $tenantSchemaName Optional tenant schema (if not set, uses current context)
     */
    public function createUser(string $username, string $password, ?string $tenantSchemaName = null): bool
    {
        try {
            // Set tenant schema if provided
            if ($tenantSchemaName) {
                $this->setTenantSchemaContext($tenantSchemaName);
            }
            
            \Log::info("RADIUS: Creating user in tenant schema: {$username}");
            
            // Get current search path for logging
            $searchPath = \DB::selectOne("SHOW search_path")->search_path ?? 'unknown';
            \Log::debug("RADIUS: Current search_path: {$searchPath}");
            
            // Insert into radcheck table (uses current search_path - tenant schema)
            \DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Store NT-Password hash for CHAP/MSCHAP2 authentication
            // Without this, only PAP works — MSCHAP2 (most common PPPoE auth) fails
            $ntHash = strtoupper(hash('md4', mb_convert_encoding($password, 'UTF-16LE', 'UTF-8')));
            \DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'NT-Password',
                'op' => ':=',
                'value' => $ntHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Add default admin attributes (optional)
            \DB::table('radreply')->insert([
                [
                    'username' => $username,
                    'attribute' => 'Service-Type',
                    'op' => ':=',
                    'value' => 'Administrative-User',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
            
            \Log::info("RADIUS: User created successfully in tenant schema: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to create user {$username}: " . $e->getMessage());
            return false;
        } finally {
            // Reset to public schema if we changed it
            if ($tenantSchemaName) {
                \DB::statement("SET search_path TO public");
            }
        }
    }

    /**
     * Delete user from RADIUS
     */
    public function deleteUser(string $username): bool
    {
        try {
            \Log::info("RADIUS: Deleting user: {$username}");
            
            \DB::table('radcheck')->where('username', $username)->delete();
            \DB::table('radreply')->where('username', $username)->delete();
            
            \Log::info("RADIUS: User deleted successfully: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to delete user {$username}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update user password in RADIUS
     */
    public function updatePassword(string $username, string $newPassword): bool
    {
        try {
            \Log::info("RADIUS: Updating password for user: {$username}");
            
            \DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->update(['value' => $newPassword]);

            // Update NT-Password hash for CHAP/MSCHAP2 compatibility
            $ntHash = strtoupper(hash('md4', mb_convert_encoding($newPassword, 'UTF-16LE', 'UTF-8')));
            \DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'NT-Password'],
                ['op' => ':=', 'value' => $ntHash, 'updated_at' => now()]
            );
            
            \Log::info("RADIUS: Password updated successfully: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to update password for {$username}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Enforce PPPoE authentication and update RADIUS auth type
     */
    public function enforceAuthentication(string $username): bool
    {
        if (!$this->authenticate($username, '')) {
            \Log::warning("RADIUS: User {$username} failed authentication, blocking access.");
            $this->updateRADIUSAuth($username, 'Reject');
            return false;
        }
        \Log::info("RADIUS: User {$username} authenticated successfully, allowing access.");
        $this->updateRADIUSAuth($username, 'Accept');
        return true;
    }

    /**
     * Update RADIUS auth type
     */
    private function updateRADIUSAuth(string $username, string $authType): bool
    {
        try {
            $exists = \DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Auth-Type')
                ->exists();

            if ($exists) {
                \DB::table('radcheck')
                    ->where('username', $username)
                    ->where('attribute', 'Auth-Type')
                    ->update(['value' => $authType]);
            } else {
                \DB::table('radcheck')->insert([
                    'username' => $username,
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => $authType,
                ]);
            }

            \Log::info("RADIUS auth updated", [
                'username' => $username,
                'auth_type' => $authType,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to update RADIUS auth", [
                'username' => $username,
                'auth_type' => $authType,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
