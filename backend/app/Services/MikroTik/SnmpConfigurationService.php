<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\MikrotikSshService;
use Illuminate\Support\Facades\Log;

class SnmpConfigurationService
{
    protected MikrotikSshService $sshService;

    public function __construct(MikrotikSshService $sshService)
    {
        $this->sshService = $sshService;
    }

    /**
     * Enable SNMP on a MikroTik router (defaults to SNMPv2c).
     *
     * If SNMPv3 credentials are provided in $options, configures v3.
     * Otherwise, enables SNMPv2c with the community string.
     */
    public function enableSnmp(Router $router, array $options = []): array
    {
        $version = $options['version'] ?? '2c';

        if ($version === '3' || $version === 'v3') {
            return $this->enableSnmpV3($router, $options);
        }

        return $this->enableSnmpV2c($router, $options);
    }

    /**
     * Enable SNMPv2c on a MikroTik router (preserves public community).
     */
    public function enableSnmpV2c(Router $router, array $options = []): array
    {
        $community = $options['community'] ?? config('telegraf.snmp_community', 'public');
        $contact = $options['contact'] ?? 'Network Admin';
        $location = $options['location'] ?? $router->location ?? 'Unknown';

        $commands = [
            '/snmp set enabled=yes',
            "/snmp set contact=\"{$contact}\"",
            "/snmp set location=\"{$location}\"",
            // Ensure community exists (idempotent: remove then re-add)
            ":do { /snmp community remove [find name=\"{$community}\"]; } on-error={}",
            "/snmp community add name=\"{$community}\" addresses=0.0.0.0/0 security=none read-access=yes write-access=no",
        ];

        try {
            $results = [];
            foreach ($commands as $command) {
                $result = $this->sshService->executeCommand($router, $command);
                $results[] = [
                    'command' => $command,
                    'output' => $result,
                ];
            }

            $router->update([
                'snmp_enabled' => true,
                'snmp_version' => '2c',
                'snmp_community' => $community,
            ]);

            Log::info('SNMPv2c enabled successfully', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'community' => $community,
            ]);

            return [
                'success' => true,
                'message' => 'SNMPv2c enabled successfully',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to enable SNMPv2c', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Configure SNMPv3 on a MikroTik router
     */
    public function enableSnmpV3(Router $router, array $credentials): array
    {
        $user = $credentials['user'] ?? config('telegraf.snmpv3_user', 'snmpmonitor');
        $authProtocol = strtolower($credentials['auth_protocol'] ?? 'SHA256');
        $authPassword = $credentials['auth_password'] ?? config('telegraf.snmpv3_auth_password', '');
        $privProtocol = strtolower($credentials['priv_protocol'] ?? 'AES');
        $privPassword = $credentials['priv_password'] ?? config('telegraf.snmpv3_priv_password', '');
        $contact = $credentials['contact'] ?? 'Network Admin';
        $location = $credentials['location'] ?? $router->location ?? 'Unknown';

        if (empty($authPassword) || empty($privPassword)) {
            throw new \InvalidArgumentException('SNMPv3 requires both auth and priv passwords');
        }

        $commands = [
            '/snmp set enabled=yes',
            "/snmp set contact=\"{$contact}\"",
            "/snmp set location=\"{$location}\"",
            // Remove existing v3 user before adding (idempotent)
            ":do { /snmp community remove [find name={$user}]; } on-error={}",
            // Add SNMPv3 user
            "/snmp community add name={$user} addresses=0.0.0.0/0 security=private " .
            "authentication-protocol={$authProtocol} authentication-password=\"{$authPassword}\" " .
            "encryption-protocol={$privProtocol} encryption-password=\"{$privPassword}\"",
        ];

        try {
            $results = [];
            foreach ($commands as $command) {
                $result = $this->sshService->executeCommand($router, $command);
                $results[] = [
                    'command' => $command,
                    'output' => $result,
                ];
            }

            $router->update([
                'snmp_enabled' => true,
                'snmp_version' => 'v3',
                'snmp_v3_user' => $user,
                'snmp_v3_auth_protocol' => strtoupper($authProtocol),
                'snmp_v3_auth_password' => $authPassword,
                'snmp_v3_priv_protocol' => strtoupper($privProtocol),
                'snmp_v3_priv_password' => $privPassword,
            ]);

            Log::info('SNMPv3 enabled successfully', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'user' => $user,
            ]);

            return [
                'success' => true,
                'message' => 'SNMPv3 enabled successfully',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to enable SNMPv3', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify SNMP is working on the router
     */
    public function verifySnmp(Router $router): array
    {
        try {
            $result = $this->sshService->executeCommand($router, '/snmp print');
            
            $enabled = str_contains($result, 'enabled: yes');
            
            return [
                'success' => true,
                'enabled' => $enabled,
                'output' => $result,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'enabled' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get SNMPv2c configuration script for manual application on MikroTik.
     */
    public function getSnmpConfigScript(array $options = []): string
    {
        $community = $options['community'] ?? config('telegraf.snmp_community', 'public');
        $contact = $options['contact'] ?? 'Network Admin';
        $location = $options['location'] ?? 'Unknown';

        return <<<SCRIPT
# Enable SNMPv2c for monitoring
/snmp set enabled=yes
/snmp set contact="{$contact}"
/snmp set location="{$location}"
:do { /snmp community remove [find name="{$community}"]; } on-error={}
/snmp community add name="{$community}" addresses=0.0.0.0/0 security=none read-access=yes write-access=no
SCRIPT;
    }
}
