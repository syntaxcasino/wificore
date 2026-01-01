<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Log authentication events
     */
    public static function logAuthentication(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('authentication', $action, $userId, array_merge($details, [
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]));
    }

    /**
     * Log payment events
     */
    public static function logPayment(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('payment', $action, $userId, $details);
    }

    /**
     * Log router provisioning events
     */
    public static function logProvisioning(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('provisioning', $action, $userId, $details);
    }

    /**
     * Log user management events
     */
    public static function logUserManagement(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('user_management', $action, $userId, $details);
    }

    /**
     * Log permission changes
     */
    public static function logPermissionChange(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('permission_change', $action, $userId, $details);
    }

    /**
     * Log security events
     */
    public static function logSecurity(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('security', $action, $userId, array_merge($details, [
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]));
    }

    /**
     * Log data access events
     */
    public static function logDataAccess(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('data_access', $action, $userId, $details);
    }

    /**
     * Log configuration changes
     */
    public static function logConfigChange(string $action, ?string $userId = null, array $details = []): void
    {
        self::log('config_change', $action, $userId, $details);
    }

    /**
     * Core logging method
     */
    private static function log(string $category, string $action, ?string $userId = null, array $details = []): void
    {
        try {
            $user = Auth::user();
            $tenantId = $user?->tenant_id ?? null;
            
            // If userId not provided, use authenticated user
            if (!$userId && $user) {
                $userId = $user->id;
            }

            SystemLog::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'category' => $category,
                'action' => $action,
                'details' => json_encode(array_merge($details, [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => Request::header('X-Request-ID', uniqid('req_')),
                ])),
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
            ]);
        } catch (\Exception $e) {
            // Log to file if database logging fails
            \Log::error('Audit log failed', [
                'category' => $category,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Query audit logs with filters
     */
    public static function query(array $filters = [])
    {
        $query = SystemLog::query();

        if (isset($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['ip_address'])) {
            $query->where('ip_address', $filters['ip_address']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Get recent security events
     */
    public static function getRecentSecurityEvents(int $limit = 50)
    {
        return SystemLog::where('category', 'security')
            ->orWhere('category', 'authentication')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get failed login attempts for a user
     */
    public static function getFailedLoginAttempts(string $username, int $minutes = 15): int
    {
        return SystemLog::where('category', 'authentication')
            ->where('action', 'login_failed')
            ->where('details->username', $username)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Check for suspicious activity patterns
     */
    public static function detectSuspiciousActivity(string $userId): array
    {
        $suspiciousPatterns = [];

        // Multiple failed logins
        $failedLogins = SystemLog::where('user_id', $userId)
            ->where('category', 'authentication')
            ->where('action', 'login_failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($failedLogins >= 5) {
            $suspiciousPatterns[] = [
                'type' => 'multiple_failed_logins',
                'count' => $failedLogins,
                'severity' => 'high',
            ];
        }

        // Multiple IP addresses
        $ipAddresses = SystemLog::where('user_id', $userId)
            ->where('created_at', '>=', now()->subHour())
            ->distinct('ip_address')
            ->count('ip_address');

        if ($ipAddresses >= 3) {
            $suspiciousPatterns[] = [
                'type' => 'multiple_ip_addresses',
                'count' => $ipAddresses,
                'severity' => 'medium',
            ];
        }

        // Unusual access patterns (accessing many resources quickly)
        $accessCount = SystemLog::where('user_id', $userId)
            ->where('category', 'data_access')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();

        if ($accessCount >= 100) {
            $suspiciousPatterns[] = [
                'type' => 'rapid_data_access',
                'count' => $accessCount,
                'severity' => 'high',
            ];
        }

        return $suspiciousPatterns;
    }
}
