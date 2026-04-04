<?php

namespace App\Services\RouterDriver;

use App\Models\Router;

/**
 * Router Driver Interface
 * 
 * Defines the contract that all router vendor drivers must implement.
 * This abstraction enables multi-vendor support (MikroTik, Cisco, Ubiquiti, etc.)
 * while maintaining a consistent API for the application layer.
 */
interface RouterDriverInterface
{
    /**
     * Get driver capabilities for this router model
     * 
     * @return DriverCapabilities Capabilities supported by this driver
     */
    public function getCapabilities(): DriverCapabilities;

    /**
     * Detect router vendor and model automatically
     * 
     * @param Router $router The router to detect
     * @return DeviceInfo Detected device information
     */
    public function detectDevice(Router $router): DeviceInfo;

    /**
     * Generate configuration script for the router
     * 
     * @param array $config Configuration parameters
     * @return string Generated configuration in vendor-specific format
     */
    public function generateConfig(array $config): string;

    /**
     * Apply configuration to the router
     * 
     * @param Router $router Target router
     * @param string $config Configuration to apply
     * @return bool True if successful
     */
    public function applyConfig(Router $router, string $config): bool;

    /**
     * Verify configuration on the router
     * 
     * @param Router $router Router to verify
     * @return VerificationResult Verification status
     */
    public function verifyConfig(Router $router): VerificationResult;

    /**
     * Get running configuration from router
     * 
     * @param Router $router Source router
     * @return string Current running configuration
     */
    public function getRunningConfig(Router $router): string;

    /**
     * Check if router is reachable
     * 
     * @param Router $router Router to check
     * @return ConnectionStatus Connection status
     */
    public function checkConnectivity(Router $router): ConnectionStatus;

    /**
     * Execute command on router
     * 
     * @param Router $router Target router
     * @param string $command Command to execute
     * @return CommandResult Command execution result
     */
    public function executeCommand(Router $router, string $command): CommandResult;

    /**
     * Get router system info
     * 
     * @param Router $router Router to query
     * @return SystemInfo System information
     */
    public function getSystemInfo(Router $router): SystemInfo;

    /**
     * Get active sessions (PPPoE/Hotspot)
     * 
     * @param Router $router Router to query
     * @return array Active sessions
     */
    public function getActiveSessions(Router $router): array;

    /**
     * Disconnect specific user session
     * 
     * @param Router $router Router
     * @param string $sessionId Session identifier
     * @return bool True if disconnected
     */
    public function disconnectSession(Router $router, string $sessionId): bool;

    /**
     * Reboot the router
     * 
     * @param Router $router Router to reboot
     * @param bool $soft Soft reboot (true) or hard (false)
     * @return bool True if reboot initiated
     */
    public function reboot(Router $router, bool $soft = true): bool;

    /**
     * Backup router configuration
     * 
     * @param Router $router Router to backup
     * @return string Configuration backup
     */
    public function backupConfig(Router $router): string;

    /**
     * Restore router configuration
     * 
     * @param Router $router Target router
     * @param string $config Configuration to restore
     * @return bool True if restored
     */
    public function restoreConfig(Router $router, string $config): bool;

    /**
     * Get interface list from router
     * 
     * @param Router $router Router to query
     * @return array Interface information
     */
    public function getInterfaces(Router $router): array;

    /**
     * Get router resource usage (CPU, memory, etc.)
     * 
     * @param Router $router Router to query
     * @return ResourceUsage Resource metrics
     */
    public function getResourceUsage(Router $router): ResourceUsage;

    /**
     * Validate if this driver supports the given router
     * 
     * @param Router $router Router to check
     * @return bool True if supported
     */
    public function supports(Router $router): bool;
}
