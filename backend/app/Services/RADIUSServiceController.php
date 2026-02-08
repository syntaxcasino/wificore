<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use App\Models\ServiceControlLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RADIUSServiceController extends TenantAwareService
{
    private function quoteSchemaName(string $schemaName): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schemaName)) {
            throw new \InvalidArgumentException('Invalid schema name');
        }

        return '"' . str_replace('"', '""', $schemaName) . '"';
    }

    private function getMappedSchemaForUsername(string $username): ?string
    {
        $mapping = DB::table('public.radius_user_schema_mapping')
            ->where('username', $username)
            ->where('is_active', true)
            ->first();

        if (!$mapping || empty($mapping->schema_name)) {
            return null;
        }

        return $mapping->schema_name;
    }

    private function executeInUserSchema(string $username, callable $callback)
    {
        $schemaName = $this->getMappedSchemaForUsername($username);

        if (!$schemaName) {
            return null;
        }

        return DB::transaction(function () use ($schemaName, $callback) {
            DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($schemaName) . ', public');
            return $callback();
        });
    }

    /**
     * Disconnect user from RADIUS
     * 
     * @param User $user
     * @param string $reason
     * @return bool
     */
    public function disconnectUser(User $user, string $reason): bool
    {
        try {
            // Update radcheck to reject authentication
            if (!$this->updateRADIUSAuth($user->username, 'Reject')) {
                return false;
            }
            
            // Terminate active sessions
            $this->terminateActiveSessions($user);
            
            // Log the action
            $this->logServiceControl($user, 'disconnect', $reason, 'completed');
            
            Log::info("User disconnected from RADIUS", [
                'user_id' => $user->id,
                'username' => $user->username,
                'reason' => $reason,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to disconnect user from RADIUS", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logServiceControl($user, 'disconnect', $reason, 'failed');
            
            return false;
        }
    }

    /**
     * Reconnect user to RADIUS
     * 
     * @param User $user
     * @return bool
     */
    public function reconnectUser(User $user): bool
    {
        try {
            // Update radcheck to accept authentication
            if (!$this->updateRADIUSAuth($user->username, 'Accept')) {
                return false;
            }
            
            // Log the action
            $this->logServiceControl($user, 'reconnect', 'Payment received', 'completed');
            
            Log::info("User reconnected to RADIUS", [
                'user_id' => $user->id,
                'username' => $user->username,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reconnect user to RADIUS", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logServiceControl($user, 'reconnect', 'Payment received', 'failed');
            
            return false;
        }
    }

    /**
     * Update RADIUS authentication status
     * 
     * @param string $username
     * @param string $authType
     * @return bool
     */
    private function updateRADIUSAuth(string $username, string $authType): bool
    {
        try {
            $result = $this->executeInUserSchema($username, function () use ($username, $authType) {
                $exists = DB::table('radcheck')
                    ->where('username', $username)
                    ->where('attribute', 'Auth-Type')
                    ->exists();

                if ($exists) {
                    DB::table('radcheck')
                        ->where('username', $username)
                        ->where('attribute', 'Auth-Type')
                        ->update(['value' => $authType]);
                } else {
                    DB::table('radcheck')->insert([
                        'username' => $username,
                        'attribute' => 'Auth-Type',
                        'op' => ':=',
                        'value' => $authType,
                    ]);
                }

                return true;
            });

            if ($result !== true) {
                return false;
            }
            
            Log::info("RADIUS auth updated", [
                'username' => $username,
                'auth_type' => $authType,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to update RADIUS auth", [
                'username' => $username,
                'auth_type' => $authType,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Terminate active sessions
     * 
     * @param User $user
     * @return void
     */
    private function terminateActiveSessions(User $user): void
    {
        try {
            $activeSessions = $this->getActiveSessions($user);
            
            foreach ($activeSessions as $session) {
                $this->sendCoADisconnect($session);
            }
            
            Log::info("Active sessions terminated", [
                'user_id' => $user->id,
                'sessions_count' => count($activeSessions),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to terminate active sessions", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send CoA Disconnect-Request
     * Terminates active session by removing it from the NAS (router) via SSH
     * 
     * @param array $session
     * @return bool
     */
    private function sendCoADisconnect(array $session): bool
    {
        $username = $session['username'] ?? null;
        $nasIpAddress = $session['nasipaddress'] ?? null;
        
        if (!$username) {
            Log::warning('CoA disconnect: No username in session', ['session' => $session]);
            return false;
        }

        try {
            // Find the router by NAS IP address
            $router = null;
            if ($nasIpAddress) {
                $router = \App\Models\Router::where('ip_address', $nasIpAddress)
                    ->orWhere('vpn_ip', $nasIpAddress)
                    ->first();
            }

            if (!$router) {
                // Try to find router from PppoeUser association
                $pppoeUser = \App\Models\PppoeUser::where('username', $username)->first();
                if ($pppoeUser) {
                    $router = \App\Models\Router::find($pppoeUser->router_id);
                }
            }

            if (!$router) {
                Log::warning('CoA disconnect: Router not found for session', [
                    'username' => $username,
                    'nas_ip' => $nasIpAddress,
                ]);
                return false;
            }

            // Disconnect via SSH - remove active PPPoE session
            $ssh = new \App\Services\MikroTik\SshExecutor($router, 15);
            $ssh->connect();
            
            // Try PPPoE active session removal
            $escapedUsername = addslashes($username);
            $ssh->exec("/ppp active remove [find name=\"{$escapedUsername}\"]");
            
            // Try Hotspot active session removal
            $ssh->exec("/ip hotspot active remove [find user=\"{$escapedUsername}\"]");
            
            $ssh->disconnect();

            Log::info('CoA disconnect: Session terminated via SSH', [
                'username' => $username,
                'router_id' => $router->id,
                'nas_ip' => $nasIpAddress,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('CoA disconnect: Failed to terminate session', [
                'username' => $username,
                'nas_ip' => $nasIpAddress,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get active RADIUS sessions for user
     * 
     * @param User $user
     * @return array
     */
    private function getActiveSessions(User $user): array
    {
        try {
            $sessions = $this->executeInUserSchema($user->username, function () use ($user) {
                return DB::table('radacct')
                    ->where('username', $user->username)
                    ->whereNull('acctstoptime')
                    ->get()
                    ->toArray();
            });

            if (!is_array($sessions)) {
                return [];
            }

            return array_map(function ($session) {
                return (array) $session;
            }, $sessions);
        } catch (\Exception $e) {
            Log::error("Failed to get active sessions", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }
    }

    /**
     * Log service control action
     * 
     * @param User $user
     * @param string $action
     * @param string $reason
     * @param string $status
     * @return void
     */
    private function logServiceControl(User $user, string $action, string $reason, string $status): void
    {
        try {
            $subscription = $user->subscriptions()->latest()->first();
            
            ServiceControlLog::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id ?? null,
                'action' => $action,
                'reason' => $reason,
                'status' => $status,
                'executed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to log service control action", [
                'user_id' => $user->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if user has active RADIUS sessions
     * 
     * @param User $user
     * @return bool
     */
    public function hasActiveSessions(User $user): bool
    {
        return count($this->getActiveSessions($user)) > 0;
    }

    /**
     * Get session count for user
     * 
     * @param User $user
     * @return int
     */
    public function getSessionCount(User $user): int
    {
        return count($this->getActiveSessions($user));
    }

    /**
     * Disconnect unauthenticated users based on RADIUS configuration
     * 
     * @return void
     */
    public function disconnectUnauthenticatedUsers() {
        $users = DB::table('radcheck')
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->pluck('username');

        foreach ($users as $username) {
            $this->disconnectUserByUsername($username);
        }
    }

    /**
     * Disconnect user by username
     * Finds active RADIUS sessions and terminates them on the router
     * 
     * @param string $username
     * @return void
     */
    private function disconnectUserByUsername(string $username): void
    {
        try {
            // Get active sessions for this username
            $sessions = $this->executeInUserSchema($username, function () use ($username) {
                return DB::table('radacct')
                    ->where('username', $username)
                    ->whereNull('acctstoptime')
                    ->get()
                    ->toArray();
            });

            if (!is_array($sessions) || empty($sessions)) {
                Log::info("No active sessions found for user: {$username}");
                return;
            }

            foreach ($sessions as $session) {
                $this->sendCoADisconnect((array) $session);
            }

            Log::info("Disconnected unauthenticated user", [
                'username' => $username,
                'sessions_terminated' => count($sessions),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to disconnect user by username", [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
