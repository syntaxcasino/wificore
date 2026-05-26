<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use App\Models\RouterTask;
use App\Models\ServiceControlLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ProvisioningServiceClient;

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
            // Remove forced Auth-Type so FreeRADIUS returns to normal credential checks
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
            if (!in_array($authType, ['Accept', 'Reject'], true)) {
                throw new \InvalidArgumentException('Unsupported auth type value');
            }

            $result = $this->executeInUserSchema($username, function () use ($username, $authType) {
                if ($authType === 'Reject') {
                    DB::table('radcheck')->updateOrInsert(
                        ['username' => $username, 'attribute' => 'Auth-Type'],
                        ['op' => ':=', 'value' => 'Reject']
                    );
                } else {
                    // Re-enable normal RADIUS credential checks by removing forced Auth-Type rows.
                    DB::table('radcheck')
                        ->where('username', $username)
                        ->where('attribute', 'Auth-Type')
                        ->delete();
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
            $provisioningClient = app(ProvisioningServiceClient::class);
            
            foreach ($activeSessions as $session) {
                $this->sendCoADisconnect($session, $provisioningClient);
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
     *
     * Strategy (RFC 5176 first, Go-backed fallback):
     *  1. Try RFC 5176 Disconnect-Request via CoAService (UDP/3799) — preferred, no SSH needed.
     *  2. On CoA failure, fall back to SSH-based session removal so the user is always kicked.
     *
     * @param array $session  A radacct row cast to array
     * @return bool
     */
    private function sendCoADisconnect(array $session, ProvisioningServiceClient $provisioningClient): bool
    {
        $username     = $session['username'] ?? null;
        $nasIpAddress = $session['nasipaddress'] ?? null;
        $sessionId    = $session['acctsessionid'] ?? null;

        if (!$username) {
            Log::warning('CoA disconnect: No username in session', ['session' => $session]);
            return false;
        }

        // ---------------------------------------------------------------
        // 1. RFC 5176 CoA Disconnect-Request (RADIUS over UDP/3799)
        // ---------------------------------------------------------------
        try {
            $coaService = new \App\Services\RADIUS\CoAService(
                null,   // uses config('services.radius.server')
                null,   // uses config('services.radius.coa_port', 3799)
                null    // uses config('services.radius.secret')
            );

            $result = $coaService->disconnectUser(
                $username,
                'Administrative disconnect',
                $sessionId,
                $nasIpAddress
            );

            if ($result->isSuccessful()) {
                Log::info('CoA disconnect: Session terminated via RFC 5176 CoA', [
                    'username'   => $username,
                    'session_id' => $sessionId,
                    'nas_ip'     => $nasIpAddress,
                ]);
                return true;
            }

            Log::warning('CoA disconnect: RFC 5176 failed, falling back to Go-backed execution', [
                'username' => $username,
                'message'  => $result->message,
            ]);
        } catch (\Exception $e) {
            Log::warning('CoA disconnect: CoAService exception, falling back to Go-backed execution', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
        }

        // ---------------------------------------------------------------
        // 2. Go-backed fallback — terminate session directly on the router
        // ---------------------------------------------------------------
        try {
            $router = null;
            if ($nasIpAddress) {
                // OPTIMIZED: Select only needed columns
                $router = \App\Models\Router::query()
                    ->select(['id', 'host', 'ssh_port', 'ssh_user', 'ssh_pass', 'ssh_private_key', 'ip_address', 'vpn_ip', 'username', 'password', 'port'])
                    ->where('ip_address', $nasIpAddress)
                    ->orWhere('vpn_ip', $nasIpAddress)
                    ->first();
            }

            if (!$router) {
                // OPTIMIZED: Select only needed columns
                $pppoeUser = \App\Models\PppoeUser::query()
                    ->select(['id', 'router_id'])
                    ->where('username', $username)
                    ->first();
                if ($pppoeUser) {
                    $router = \App\Models\Router::query()
                        ->select(['id', 'host', 'ssh_port', 'ssh_user', 'ssh_pass', 'ssh_private_key', 'ip_address', 'vpn_ip', 'username', 'password', 'port'])
                        ->find($pppoeUser->router_id);
                }
            }

            if (!$router) {
                Log::warning('CoA disconnect: Router not found for Go-backed fallback', [
                    'username' => $username,
                    'nas_ip'   => $nasIpAddress,
                ]);
                return false;
            }

            $escapedUsername = addslashes($username);
            $commands = [
                sprintf(':do { /ppp active remove [find name="%s"] } on-error={}', $escapedUsername),
                sprintf(':do { /ip hotspot active remove [find user="%s"] } on-error={}', $escapedUsername),
            ];

            $task = RouterTask::create([
                'tenant_id' => $this->getTenantId(),
                'router_id' => $router->id,
                'type' => RouterTask::TYPE_SERVICE_CONTROL_ACTION,
                'status' => RouterTask::STATUS_QUEUED,
                'progress' => 0,
                'message' => 'Queueing RADIUS disconnect fallback',
                'request_payload' => [
                    'context' => 'radius_disconnect_fallback',
                    'action' => 'radius_disconnect_fallback',
                    'username' => $username,
                    'session_id' => $sessionId,
                    'nas_ip' => $nasIpAddress,
                    'commands' => $commands,
                ],
            ]);

            $provisioningClient->submitTaskCommand(
                $router,
                $this->getTenantId(),
                RouterTask::TYPE_SERVICE_CONTROL_ACTION,
                ['commands' => $commands, 'context' => 'radius_disconnect_fallback', 'action' => 'radius_disconnect_fallback'],
                $task
            );

            Log::info('CoA disconnect: Session termination command submitted via Go-backed async execution', [
                'username'  => $username,
                'router_id' => $router->id,
                'nas_ip'    => $nasIpAddress,
                'task_id'   => $task->id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('CoA disconnect: Go-backed fallback also failed', [
                'username' => $username,
                'nas_ip'   => $nasIpAddress,
                'error'    => $e->getMessage(),
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
