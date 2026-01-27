<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * Router Resource Manager
 * Provides adaptive resource management based on router model and capabilities
 * Optimized for low-end devices like hAP lite
 */
class RouterResourceManager
{
    /**
     * Low-end router models that require special handling
     */
    const LOW_END_MODELS = [
        'RB941-2nD',        // hAP lite
        'RB951Ui-2HnD',     // hAP
        'RB750',            // hex lite
        'RB750r2',          // hex lite r2
        'RB750Gr3',         // hex
        'RB952Ui-5ac2nD',   // hAP ac lite
    ];
    
    /**
     * Resource limits for different router tiers
     */
    const RESOURCE_LIMITS = [
        'low_end' => [
            'max_firewall_rules' => 50,
            'max_nat_rules' => 20,
            'max_concurrent_connections' => 500,
            'polling_interval' => 300,      // 5 minutes
            'command_batch_size' => 5,
            'ssh_timeout' => 15,
            'verification_attempts' => 2,
        ],
        'mid_range' => [
            'max_firewall_rules' => 100,
            'max_nat_rules' => 50,
            'max_concurrent_connections' => 2000,
            'polling_interval' => 120,      // 2 minutes
            'command_batch_size' => 10,
            'ssh_timeout' => 20,
            'verification_attempts' => 3,
        ],
        'high_end' => [
            'max_firewall_rules' => 200,
            'max_nat_rules' => 100,
            'max_concurrent_connections' => 10000,
            'polling_interval' => 60,       // 1 minute
            'command_batch_size' => 20,
            'ssh_timeout' => 30,
            'verification_attempts' => 5,
        ],
    ];
    
    /**
     * Detect router tier based on model
     */
    public static function getRouterTier(Router $router): string
    {
        $model = $router->model ?? '';
        
        // Check if it's a low-end model
        foreach (self::LOW_END_MODELS as $lowEndModel) {
            if (stripos($model, $lowEndModel) !== false) {
                return 'low_end';
            }
        }
        
        // Check for high-end indicators
        if (stripos($model, 'CCR') !== false || 
            stripos($model, 'CRS') !== false ||
            stripos($model, 'RB1100') !== false) {
            return 'high_end';
        }
        
        // Default to mid-range
        return 'mid_range';
    }
    
    /**
     * Get resource limits for a router
     */
    public static function getResourceLimits(Router $router): array
    {
        $tier = self::getRouterTier($router);
        return self::RESOURCE_LIMITS[$tier];
    }
    
    /**
     * Get optimal polling interval for a router
     */
    public static function getPollingInterval(Router $router): int
    {
        $limits = self::getResourceLimits($router);
        return $limits['polling_interval'];
    }
    
    /**
     * Get optimal SSH timeout for a router
     */
    public static function getSshTimeout(Router $router): int
    {
        $limits = self::getResourceLimits($router);
        return $limits['ssh_timeout'];
    }
    
    /**
     * Get optimal verification attempts for a router
     */
    public static function getVerificationAttempts(Router $router): int
    {
        $limits = self::getResourceLimits($router);
        return $limits['verification_attempts'];
    }
    
    /**
     * Get optimal command batch size for a router
     */
    public static function getCommandBatchSize(Router $router): int
    {
        $limits = self::getResourceLimits($router);
        return $limits['command_batch_size'];
    }
    
    /**
     * Check if router can handle additional firewall rules
     */
    public static function canAddFirewallRule(Router $router, int $currentRuleCount): bool
    {
        $limits = self::getResourceLimits($router);
        return $currentRuleCount < $limits['max_firewall_rules'];
    }
    
    /**
     * Check if router can handle additional NAT rules
     */
    public static function canAddNatRule(Router $router, int $currentRuleCount): bool
    {
        $limits = self::getResourceLimits($router);
        return $currentRuleCount < $limits['max_nat_rules'];
    }
    
    /**
     * Get optimized script generation settings for router
     */
    public static function getScriptSettings(Router $router): array
    {
        $tier = self::getRouterTier($router);
        
        return [
            'tier' => $tier,
            'minimize_rules' => $tier === 'low_end',
            'use_address_lists' => $tier !== 'low_end', // Address lists use more memory
            'enable_logging' => $tier !== 'low_end',    // Logging uses resources
            'batch_commands' => true,
            'command_batch_size' => self::getCommandBatchSize($router),
        ];
    }
    
    /**
     * Log router resource information
     */
    public static function logResourceInfo(Router $router): void
    {
        $tier = self::getRouterTier($router);
        $limits = self::getResourceLimits($router);
        
        Log::info('Router resource configuration', [
            'router_id' => $router->id,
            'router_model' => $router->model,
            'tier' => $tier,
            'polling_interval' => $limits['polling_interval'],
            'ssh_timeout' => $limits['ssh_timeout'],
            'max_firewall_rules' => $limits['max_firewall_rules'],
        ]);
    }
    
    /**
     * Get memory-optimized configuration for low-end devices
     */
    public static function getMemoryOptimizedConfig(Router $router): array
    {
        $tier = self::getRouterTier($router);
        
        if ($tier !== 'low_end') {
            return [];
        }
        
        return [
            'disable_connection_tracking' => false, // Keep enabled for functionality
            'reduce_connection_timeout' => true,
            'connection_timeout' => '1h',           // Reduced from default 1d
            'disable_fasttrack' => false,           // Keep fasttrack for performance
            'optimize_firewall' => true,
            'use_simple_queues' => true,            // Simple queues use less memory
            'max_queue_count' => 50,
        ];
    }
}
