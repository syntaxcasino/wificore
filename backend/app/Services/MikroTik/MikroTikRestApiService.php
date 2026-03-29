<?php

declare(strict_types=1);

namespace App\Services\MikroTik;

use App\Models\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MikroTik REST API Client for low-end device provisioning
 * 
 * Provides HTTP-based configuration for hAP lite and other low-end devices
 * to avoid SSH connection timeout issues during CPU-intensive operations.
 * 
 * Requires RouterOS 6.45+ with REST API enabled:
 *   /ip service enable rest-api
 *   /ip service set rest-api port=8729
 * 
 * API Authentication: Basic Auth (username/password)
 */
class MikroTikRestApiService
{
    private Router $router;
    private string $baseUrl;
    private string $username;
    private string $password;
    private int $timeout;
    private bool $verifySsl;

    /**
     * Initialize REST API service with router credentials
     */
    public function __construct(Router $router, int $timeout = 30, bool $verifySsl = false)
    {
        $this->router = $router;
        $this->timeout = $timeout;
        $this->verifySsl = $verifySsl;

        // Parse IP address (may include /mask)
        $ipAddress = $router->ip_address;
        if (str_contains($ipAddress, '/')) {
            [$ipAddress] = explode('/', $ipAddress, 2);
        }

        // Default REST API port is 8729
        $port = $router->api_port ?? 8729;
        $this->baseUrl = "https://{$ipAddress}:{$port}/rest";

        $this->username = $router->username;
        $this->password = Crypt::decrypt($router->password);
    }

    /**
     * Test API connectivity
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('/system/resource');
            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('MikroTik API connection test failed', [
                'router_id' => $this->router->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create bridge interface
     */
    public function createBridge(string $name, ?string $comment = null): array
    {
        $data = ['name' => $name];
        if ($comment) {
            $data['comment'] = $comment;
        }

        return $this->post('/interface/bridge/add', $data);
    }

    /**
     * Remove bridge by name
     */
    public function removeBridge(string $name): bool
    {
        try {
            // Find bridge ID first
            $bridges = $this->get('/interface/bridge/print');
            foreach ($bridges->json() as $bridge) {
                if ($bridge['name'] === $name) {
                    $this->post('/interface/bridge/remove', ['numbers' => $bridge['.id']]);
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove bridge', [
                'router_id' => $this->router->id,
                'bridge' => $name,
                'error' => $e->getMessage(),
            ]);
        }
        return false;
    }

    /**
     * Add port to bridge
     */
    public function addBridgePort(string $bridge, string $interface, ?string $comment = null): array
    {
        $data = [
            'bridge' => $bridge,
            'interface' => $interface,
        ];
        if ($comment) {
            $data['comment'] = $comment;
        }

        return $this->post('/interface/bridge/port/add', $data);
    }

    /**
     * Remove bridge port
     */
    public function removeBridgePort(string $interface): bool
    {
        try {
            $ports = $this->get('/interface/bridge/port/print');
            foreach ($ports->json() as $port) {
                if ($port['interface'] === $interface) {
                    $this->post('/interface/bridge/port/remove', ['numbers' => $port['.id']]);
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove bridge port', [
                'router_id' => $this->router->id,
                'interface' => $interface,
                'error' => $e->getMessage(),
            ]);
        }
        return false;
    }

    /**
     * Add VLAN interface
     */
    public function addVlan(string $name, int $vlanId, string $interface, ?string $comment = null): array
    {
        $data = [
            'name' => $name,
            'vlan-id' => $vlanId,
            'interface' => $interface,
        ];
        if ($comment) {
            $data['comment'] = $comment;
        }

        return $this->post('/interface/vlan/add', $data);
    }

    /**
     * Remove VLAN by name
     */
    public function removeVlan(string $name): bool
    {
        try {
            $vlans = $this->get('/interface/vlan/print');
            foreach ($vlans->json() as $vlan) {
                if ($vlan['name'] === $name) {
                    $this->post('/interface/vlan/remove', ['numbers' => $vlan['.id']]);
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove VLAN', [
                'router_id' => $this->router->id,
                'vlan' => $name,
                'error' => $e->getMessage(),
            ]);
        }
        return false;
    }

    /**
     * Add interface list member
     */
    public function addInterfaceListMember(string $list, string $interface): array
    {
        return $this->post('/interface/list/member/add', [
            'list' => $list,
            'interface' => $interface,
        ]);
    }

    /**
     * Create PPPoE server
     */
    public function createPppoeServer(
        string $serviceName,
        string $interface,
        string $profile,
        int $maxMtu = 1480,
        int $maxMru = 1480,
        bool $oneSessionPerHost = true,
        int $keepaliveTimeout = 30
    ): array {
        return $this->post('/interface/pppoe-server/server/add', [
            'service-name' => $serviceName,
            'interface' => $interface,
            'default-profile' => $profile,
            'max-mtu' => $maxMtu,
            'max-mru' => $maxMru,
            'one-session-per-host' => $oneSessionPerHost ? 'yes' : 'no',
            'keepalive-timeout' => $keepaliveTimeout,
            'authentication' => 'chap,mschap2',
            'disabled' => 'no',
        ]);
    }

    /**
     * Remove PPPoE server by service name
     */
    public function removePppoeServer(string $serviceName): bool
    {
        try {
            $servers = $this->get('/interface/pppoe-server/server/print');
            foreach ($servers->json() as $server) {
                if ($server['service-name'] === $serviceName) {
                    $this->post('/interface/pppoe-server/server/remove', ['numbers' => $server['.id']]);
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove PPPoE server', [
                'router_id' => $this->router->id,
                'service_name' => $serviceName,
                'error' => $e->getMessage(),
            ]);
        }
        return false;
    }

    /**
     * Add RADIUS server
     */
    public function addRadiusServer(
        string $service,
        string $address,
        string $secret,
        int $timeout = 3,
        ?string $comment = null
    ): array {
        $data = [
            'service' => $service,
            'address' => $address,
            'secret' => $secret,
            'timeout' => $timeout,
        ];
        if ($comment) {
            $data['comment'] = $comment;
        }

        return $this->post('/radius/add', $data);
    }

    /**
     * Remove RADIUS servers by comment pattern
     */
    public function removeRadiusByComment(string $commentPattern): void
    {
        try {
            $servers = $this->get('/radius/print');
            foreach ($servers->json() as $server) {
                if (isset($server['comment']) && str_contains($server['comment'], $commentPattern)) {
                    $this->post('/radius/remove', ['numbers' => $server['.id']]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove RADIUS servers', [
                'router_id' => $this->router->id,
                'pattern' => $commentPattern,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add firewall filter rule
     */
    public function addFirewallFilterRule(array $params): array
    {
        return $this->post('/ip/firewall/filter/add', $params);
    }

    /**
     * Remove firewall filter rules by comment pattern
     */
    public function removeFirewallFilterByComment(string $commentPattern): void
    {
        try {
            $rules = $this->get('/ip/firewall/filter/print');
            foreach ($rules->json() as $rule) {
                if (isset($rule['comment']) && str_contains($rule['comment'], $commentPattern)) {
                    $this->post('/ip/firewall/filter/remove', ['numbers' => $rule['.id']]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove firewall rules', [
                'router_id' => $this->router->id,
                'pattern' => $commentPattern,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add NAT rule
     */
    public function addNatRule(array $params): array
    {
        return $this->post('/ip/firewall/nat/add', $params);
    }

    /**
     * Remove NAT rules by comment
     */
    public function removeNatByComment(string $comment): void
    {
        try {
            $rules = $this->get('/ip/firewall/nat/print');
            foreach ($rules->json() as $rule) {
                if (isset($rule['comment']) && $rule['comment'] === $comment) {
                    $this->post('/ip/firewall/nat/remove', ['numbers' => $rule['.id']]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove NAT rules', [
                'router_id' => $this->router->id,
                'comment' => $comment,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set connection tracking settings
     */
    public function setConnectionTracking(int $tcpEstablishedTimeout = 3600, int $udpTimeout = 30): array
    {
        return $this->post('/ip/firewall/connection/tracking/set', [
            'tcp-established-timeout' => $tcpEstablishedTimeout,
            'udp-timeout' => $udpTimeout,
        ]);
    }

    /**
     * Check if PPPoE server exists
     */
    public function pppoeServerExists(string $serviceName): bool
    {
        try {
            $response = $this->get('/interface/pppoe-server/server/print');
            $servers = $response->json();
            foreach ($servers as $server) {
                if ($server['service-name'] === $serviceName) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to check PPPoE server existence', [
                'router_id' => $this->router->id,
                'service_name' => $serviceName,
                'error' => $e->getMessage(),
            ]);
        }
        return false;
    }

    /**
     * Execute arbitrary command via API (for custom operations)
     */
    public function executeCommand(string $endpoint, array $params = []): array
    {
        return $this->post($endpoint, $params);
    }

    /**
     * Fetch list data from API endpoint
     */
    public function fetch(string $endpoint): array
    {
        return $this->get($endpoint)->json() ?? [];
    }

    /**
     * Make GET request to API
     */
    private function get(string $endpoint): \Illuminate\Http\Client\Response
    {
        $url = $this->baseUrl . $endpoint;

        Log::debug('MikroTik API GET', [
            'router_id' => $this->router->id,
            'endpoint' => $endpoint,
        ]);

        $response = Http::withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout)
            ->withoutVerifying()
            ->get($url);

        if (!$response->successful()) {
            throw new \Exception("API GET failed: {$response->status()} - {$response->body()}");
        }

        return $response;
    }

    /**
     * Make POST request to API
     */
    private function post(string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        Log::debug('MikroTik API POST', [
            'router_id' => $this->router->id,
            'endpoint' => $endpoint,
            'data_keys' => array_keys($data),
        ]);

        $response = Http::withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout)
            ->withoutVerifying()
            ->asForm()
            ->post($url, $data);

        if (!$response->successful()) {
            throw new \Exception("API POST failed: {$response->status()} - {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
