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
            env('RADIUS_SERVER_HOST', 'traidnet-freeradius')
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

    public function authenticate(string $username, string $password): bool
    {
        try {
            \Log::info("RADIUS: Attempting authentication for user: {$username}");
            
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
     * Create new user in RADIUS
     */
    public function createUser(string $username, string $password): bool
    {
        try {
            \Log::info("RADIUS: Creating user: {$username}");
            
            // Insert into radcheck table
            \DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
            ]);
            
            // Add default admin attributes (optional)
            \DB::table('radreply')->insert([
                [
                    'username' => $username,
                    'attribute' => 'Service-Type',
                    'op' => ':=',
                    'value' => 'Administrative-User',
                ],
            ]);
            
            \Log::info("RADIUS: User created successfully: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to create user {$username}: " . $e->getMessage());
            return false;
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
            
            \Log::info("RADIUS: Password updated successfully: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to update password for {$username}: " . $e->getMessage());
            return false;
        }
    }
}
