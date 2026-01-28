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
     * Enable and configure SNMP on a MikroTik router
     */
    public function enableSnmp(Router $router, array $options = []): array
    {
        $community = $options['community'] ?? env('TELEGRAF_SNMP_COMMUNITY', 'public');
        $contact = $options['contact'] ?? 'Network Admin';
        $location = $options['location'] ?? $router->location ?? 'Unknown';
        
        $commands = [
            // Enable SNMP
            '/snmp set enabled=yes',
            "/snmp set contact=\"{$contact}\"",
            "/snmp set location=\"{$location}\"",
            
            // Set community (v2c)
            "/snmp community set [find name=public] addresses=0.0.0.0/0 name={$community}",
            
            // Enable trap
            '/snmp set trap-version=2',
            '/snmp set trap-community=' . $community,
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

            // Update router SNMP settings in database
            $router->update([
                'snmp_enabled' => true,
                'snmp_version' => 'v2c',
            ]);

            Log::info('SNMP enabled successfully', [
                'router_id' => $router->id,
                'router_name' => $router->name,
            ]);

            return [
                'success' => true,
                'message' => 'SNMP enabled successfully',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to enable SNMP', [
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
        $user = $credentials['user'] ?? 'snmpuser';
        $authProtocol = strtolower($credentials['auth_protocol'] ?? 'SHA1');
        $authPassword = $credentials['auth_password'] ?? '';
        $privProtocol = strtolower($credentials['priv_protocol'] ?? 'AES');
        $privPassword = $credentials['priv_password'] ?? '';
        $contact = $credentials['contact'] ?? 'Network Admin';
        $location = $credentials['location'] ?? $router->location ?? 'Unknown';

        if (empty($authPassword) || empty($privPassword)) {
            throw new \InvalidArgumentException('SNMPv3 requires both auth and priv passwords');
        }

        $commands = [
            // Enable SNMP
            '/snmp set enabled=yes',
            "/snmp set contact=\"{$contact}\"",
            "/snmp set location=\"{$location}\"",
            
            // Remove default public community for security
            '/snmp community remove [find name=public]',
            
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

            // Update router SNMP settings in database
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
     * Get SNMP configuration script for manual application
     */
    public function getSnmpConfigScript(array $options = []): string
    {
        $community = $options['community'] ?? env('TELEGRAF_SNMP_COMMUNITY', 'public');
        $contact = $options['contact'] ?? 'Network Admin';
        $location = $options['location'] ?? 'Unknown';

        return <<<SCRIPT
# Enable SNMP for monitoring
/snmp set enabled=yes
/snmp set contact="{$contact}"
/snmp set location="{$location}"
/snmp community set [find name=public] addresses=0.0.0.0/0 name={$community}
/snmp set trap-version=2
/snmp set trap-community={$community}
SCRIPT;
    }
}
