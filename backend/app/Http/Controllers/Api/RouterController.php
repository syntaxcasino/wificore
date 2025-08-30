<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use RouterOS\Client;
use RouterOS\Query;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\QueryException;

class RouterController extends Controller
{
    public function index()
    {
        try {
            $routers = Router::all();
            return response()->json($routers);
        } catch (\Exception $e) {
            Log::error('Failed to fetch routers: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch routers'], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $ipAddress = $this->generateUniqueIp();
            $username = 'traidnet_user';
            $password = Str::random(12);
            $port = 8728;
            $configToken = Str::uuid();

            $router = Router::create([
                'name' => $request->name,
                'ip_address' => $ipAddress,
                'username' => $username,
                'password' => Crypt::encrypt($password),
                'port' => $port,
                'config_token' => $configToken,
                'status' => 'pending',
            ]);

            $connectivityScript = $this->generateConnectivityScript($router);
            RouterConfig::create([
                'router_id' => $router->id,
                'config_type' => 'connectivity',
                'config_content' => $connectivityScript,
            ]);

            Log::info('Router created successfully:', [
                'router_id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
                'username' => $router->username,
                'port' => $router->port,
            ]);

            return response()->json([
                'id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
                'config_token' => $router->config_token,
                'connectivity_script' => $connectivityScript,
                'status' => $router->status,
                'model' => $router->model,
                'os_version' => $router->os_version,
                'last_seen' => $router->last_seen,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create router: ' . $e->getMessage(), [
                'name' => $request->name,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to create router: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Router $router)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            'config_token' => 'nullable|string|max:255',
        ]);

        try {
            $router->update([
                'name' => $request->name,
                'ip_address' => $request->ip_address ?? $router->ip_address,
                'config_token' => $request->config_token ?? $router->config_token,
            ]);

            Log::info('Router updated successfully:', [
                'router_id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
            ]);

            return response()->json($router);
        } catch (\Exception $e) {
            Log::error('Failed to update router: ' . $e->getMessage(), [
                'router_id' => $router->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to update router: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Router $router)
    {
        try {
            $router->delete();
            Log::info('Router deleted successfully:', ['router_id' => $router->id]);
            return response()->json(['message' => 'Router deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete router: ' . $e->getMessage(), [
                'router_id' => $router->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to delete router: ' . $e->getMessage()], 500);
        }
    }

    public function verifyConnectivity(Router $router)
    {
        Log::info('verifyConnectivity called for router:', [
            'router_id' => $router->id,
            'ip_address' => $router->ip_address,
            'username' => $router->username,
            'port' => $router->port,
        ]);

        try {
            $host = explode('/', $router->ip_address)[0];
            Log::info('Connecting to RouterOS:', [
                'router_id' => $router->id,
                'host' => $host,
                'username' => $router->username,
                'port' => $router->port,
            ]);

            $client = new Client([
                'host' => $host,
                'user' => $router->username,
                'pass' => Crypt::decrypt($router->password),
                'port' => $router->port,
            ]);

            $query = new Query('/system/resource/print');
            $resources = $client->query($query)->read();
            $resource = $resources[0] ?? [];

            $query = new Query('/interface/print');
            $interfaces = $client->query($query)->read();
            $available_interfaces = array_map(function ($iface) {
                return ['name' => $iface['name'], 'type' => $iface['type'] ?? 'unknown'];
            }, $interfaces);

            $query = new Query('/interface/wireguard/peers/print');
            $peers = $client->query($query)->read();

            $router->update([
                'model' => $resource['board-name'] ?? $router->model,
                'os_version' => $resource['version'] ?? $router->os_version,
                'last_seen' => now(),
                'status' => 'active',
            ]);

            Log::info('Connectivity verified successfully for router:', [
                'router_id' => $router->id,
                'model' => $router->model,
                'os_version' => $router->os_version,
                'interfaces_count' => count($available_interfaces),
            ]);

            return response()->json([
                'status' => 'connected',
                'model' => $router->model,
                'os_version' => $router->os_version,
                'last_seen' => $router->last_seen,
                'interfaces' => $available_interfaces,
                'peers' => $peers,
            ]);
        } catch (\Exception $e) {
            $errorMessage = match (true) {
                strpos($e->getMessage(), 'Connection refused') !== false => 'Connection refused. Ensure the router is online and API port (8728) is open.',
                strpos($e->getMessage(), 'Invalid user name or password') !== false => 'Invalid credentials. Verify the username and password match the connectivity script.',
                strpos($e->getMessage(), 'decrypt') !== false => 'Failed to decrypt password. Check OpenSSL configuration and database integrity.',
                default => 'Failed to connect to router: ' . $e->getMessage(),
            };

            Log::error('Failed to verify connectivity: ' . $e->getMessage(), [
                'router_id' => $router->id,
                'ip_address' => $router->ip_address,
                'username' => $router->username,
                'port' => $router->port,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'disconnected',
                'error' => $errorMessage,
            ], 500);
        }
    }

    public function generateConfigs(Request $request, Router $router)
    {
        $request->validate([
            'hotspot_interfaces' => 'nullable|array',
            'hotspot_interfaces.*' => 'string',
            'pppoe_interfaces' => 'nullable|array',
            'pppoe_interfaces.*' => 'string',
            'enable_hotspot' => 'boolean',
            'enable_pppoe' => 'boolean',
        ]);

        try {
            $interfaceAssignments = $request->input('interface_assignments', []);
            $interfaceServices = $request->input('interface_services', []);
            $configurations = $request->input('configurations', []);

            Log::info('generateConfigs called', [
                'router_id' => $router->id,
                'interface_assignments' => $interfaceAssignments,
                'interface_services' => $interfaceServices,
                'hotspot_interfaces' => $request->hotspot_interfaces,
                'enable_hotspot' => $request->boolean('enable_hotspot'),
            ]);

            if ($request->boolean('enable_hotspot') && is_array($request->hotspot_interfaces)) {
                foreach ($request->hotspot_interfaces as $iface) {
                    if (!in_array($iface, $interfaceAssignments)) {
                        $interfaceAssignments[] = $iface;
                    }
                    $interfaceServices[$iface] = 'hotspot';
                    $configurations[$iface] = $configurations[$iface] ?? [
                        'hotspot_profile' => "hotspot-profile-$iface",
                        'ip_pool' => "192.168.88.10-192.168.88.100",
                    ];
                }
            }

            if ($request->boolean('enable_pppoe') && is_array($request->pppoe_interfaces)) {
                foreach ($request->pppoe_interfaces as $iface) {
                    if (!in_array($iface, $interfaceAssignments)) {
                        $interfaceAssignments[] = $iface;
                    }
                    $interfaceServices[$iface] = 'pppoe';
                    $configurations[$iface] = $configurations[$iface] ?? [
                        'pppoe_service' => $request->pppoe_service_name ?: 'pppoe-service',
                        'ip_pool' => $request->pppoe_ip_pool ?: '192.168.89.10-192.168.89.100',
                    ];
                }
            }

            $serviceScript = $this->generateServiceScript($interfaceAssignments, $interfaceServices, $configurations);

            RouterConfig::create([
                'router_id' => $router->id,
                'config_type' => 'service',
                'config_content' => $serviceScript,
            ]);

            Log::info('Service configuration generated for router:', [
                'router_id' => $router->id,
                'interface_assignments' => $interfaceAssignments,
            ]);

            return response()->json(['service_script' => $serviceScript]);

        } catch (\Exception $e) {
            Log::error('Failed to generate service configuration: ' . $e->getMessage(), [
                'router_id' => $router->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to generate service configuration: ' . $e->getMessage()], 500);
        }
    }

    private function generateUniqueIp()
    {
        $subnet = '192.168.56';
        $cidr = 24;
        $maxAttempts = 10;
        $attempt = 0;

        do {
            $lastOctet = rand(2, 254);
            $ipAddress = "$subnet.$lastOctet/$cidr";
            $exists = Router::where('ip_address', $ipAddress)->exists();
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);

        if ($exists) {
            throw new \Exception('Unable to generate unique IP address after maximum attempts');
        }

        return $ipAddress;
    }

    private function generateConnectivityScript(Router $router)
    {
        $decryptedPassword = Crypt::decrypt($router->password);
        return <<<EOT
/ip address add address={$router->ip_address} interface=ether2
/ip service set api disabled=no port={$router->port}
/ip service set ssh disabled=no port=22 address=""
/user add name={$router->username} password="$decryptedPassword" group=full
/ip firewall filter add chain=input protocol=tcp dst-port=22 action=accept place-before=0 comment="Allow SSH access"
/system identity set name="{$router->name}"
/system note set note="Managed by Traidnet Solution LTD"
EOT;
    }
private function generateServiceScript(array $interfaceAssignments, array $interfaceServices, array $configurations): string
{
    Log::info('Starting generateServiceScript', [
        'interface_assignments' => $interfaceAssignments,
        'interface_services' => $interfaceServices,
        'configurations' => $configurations,
    ]);

    $startTime = microtime(true);
    $scriptLines = [
        '# Generated by Traidnet Solution LTD',
        '# Common Configuration',
        '/interface list',
        'add name=LAN',
        'add name=WAN',
        '/interface list member',
        'add list=WAN interface=ether1',
    ];

    // Collect hotspot interfaces
    $hotspotInterfaces = array_values(array_filter($interfaceAssignments, 
        fn($iface) => isset($interfaceServices[$iface]) && $interfaceServices[$iface] === 'hotspot'
    ));

    if (!empty($hotspotInterfaces)) {
        $scriptLines[] = '';
        $scriptLines[] = '# Hotspot Configuration';
        $bridgeName = 'br-hotspot';

        // Bridge setup
        $scriptLines[] = '/interface bridge';
        $scriptLines[] = "add name=$bridgeName";
        $scriptLines[] = '/interface bridge port';
        foreach ($hotspotInterfaces as $iface) {
            if (preg_match('/^[a-zA-Z0-9\-_]+$/', $iface)) {
                $scriptLines[] = "add bridge=$bridgeName interface=$iface";
            } else {
                Log::warning('Invalid interface name skipped', ['interface' => $iface]);
            }
        }

        // Network parameters
        $firstIface = $hotspotInterfaces[0];
        $ipPool = $configurations[$firstIface]['ip_pool'] ?? $this->generateRandomPool('192.168');
        $network = $this->getNetworkFromPool($ipPool);
        $gateway = $this->getGatewayFromNetwork($network);

        // IP and DHCP with MAC binding
        $scriptLines[] = '/ip pool';
        $scriptLines[] = "add name=pool-hotspot ranges=$ipPool";
        $scriptLines[] = '/ip address';
        $scriptLines[] = "add address=$gateway/24 interface=$bridgeName";
        
        // Enable DHCP with MAC-based IP assignment
        $scriptLines[] = '/ip dhcp-server';
        $scriptLines[] = "add name=dhcp-hotspot address-pool=pool-hotspot interface=$bridgeName disabled=no lease-time=30m";
        $scriptLines[] = '/ip dhcp-server network';
        $scriptLines[] = "add address=$network dns-server=8.8.8.8,8.8.4.4 gateway=$gateway";
        $scriptLines[] = '/ip dhcp-server lease';
        $scriptLines[] = "add address=$gateway mac-address=00:00:00:00:00:00 comment=gateway-reservation disabled=yes";

        // Hotspot Profile
        $profileName = 'hs-prof';
        $scriptLines[] = '/ip hotspot profile';
        $scriptLines[] = "add name=$profileName";
        $scriptLines[] = "/ip hotspot profile set $profileName hotspot-address=$gateway";
        $scriptLines[] = "/ip hotspot profile set $profileName login-by=http-chap,mac-cookie";
        $scriptLines[] = "/ip hotspot profile set $profileName rate-limit=10M/10M";
        //$scriptLines[] = "/ip hotspot profile set $profileName idle-timeout=30m";

        // Hotspot Server (explicitly enabled)
        $scriptLines[] = '/ip hotspot';
        $scriptLines[] = "add name=hs-hotspot interface=$bridgeName profile=$profileName address-pool=pool-hotspot disabled=no";

        // User Profile with MAC cookie
        $userProfileName = 'hs-user';
        $scriptLines[] = '/ip hotspot user profile';
        $scriptLines[] = "add name=$userProfileName add-mac-cookie=yes rate-limit=10M/10M";

        // Add to LAN list
        $scriptLines[] = '/interface list member';
        $scriptLines[] = "add list=LAN interface=$bridgeName";
    }

    // Firewall Configuration
    $scriptLines[] = '';
    $scriptLines[] = '# Firewall Configuration';
    $scriptLines[] = '/ip firewall nat';
    $scriptLines[] = 'add chain=srcnat out-interface-list=WAN action=masquerade';
    $scriptLines[] = '/ip firewall filter';
    $scriptLines[] = 'add chain=forward action=accept connection-state=established,related';
    $scriptLines[] = 'add chain=forward action=drop connection-state=invalid';
    $scriptLines[] = 'add chain=forward action=drop connection-state=new connection-nat-state=!dstnat in-interface-list=LAN out-interface-list=WAN';

    if (!empty($hotspotInterfaces)) {
        $scriptLines[] = "# Hotspot Firewall Rules for $bridgeName";
        $scriptLines[] = '/ip firewall filter';
        $scriptLines[] = "add chain=input in-interface=$bridgeName protocol=tcp dst-port=80 action=accept comment=\"Allow HTTP to hotspot\"";
        $scriptLines[] = "add chain=input in-interface=$bridgeName protocol=tcp dst-port=443 action=accept comment=\"Allow HTTPS to hotspot\"";
        $scriptLines[] = "add chain=input in-interface=$bridgeName protocol=udp dst-port=53 action=accept comment=\"Allow DNS to hotspot\"";
    }

    $endTime = microtime(true);
    Log::info('generateServiceScript completed', [
        'execution_time' => $endTime - $startTime,
        'script_lines_count' => count($scriptLines),
    ]);

    return implode("\n", $scriptLines);
}
    private function generateRandomPool(string $prefix): string
    {
        $thirdOctet = rand(10, 250);
        $start = rand(10, 100);
        $end = $start + 50;
        return "$prefix.$thirdOctet.$start-$prefix.$thirdOctet.$end";
    }

    private function getNetworkFromPool(string $pool): string
    {
        $parts = explode('-', $pool);
        $ipParts = explode('.', $parts[0]);
        return "{$ipParts[0]}.{$ipParts[1]}.{$ipParts[2]}.0/24";
    }

    private function getGatewayFromNetwork(string $network): string
    {
        $parts = explode('.', $network);
        return "{$parts[0]}.{$parts[1]}.{$parts[2]}.1";
    }

    private function validateRouterOsScript($script)
    {
        $lines = explode("\n", $script);
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (substr_count($line, '"') % 2 !== 0) {
                Log::error('Invalid script: Unclosed quotes detected', [
                    'line_number' => $index + 1,
                    'line_content' => $line,
                ]);
                return false;
            }
            if (preg_match('/[;{}]/', $line)) {
                Log::error('Invalid script: Disallowed characters (;, {}, etc.) detected', [
                    'line_number' => $index + 1,
                    'line_content' => $line,
                ]);
                return false;
            }
            if (preg_match('/\b(set|add)\s+[^=]*$/', $line)) {
                Log::error('Invalid script: Incomplete command detected', [
                    'line_number' => $index + 1,
                    'line_content' => $line,
                ]);
                return false;
            }
        }
        return true;
    }

    public function applyConfigs(Request $request, $routerId)
    {
        $router = Router::findOrFail($routerId);
        $initialHost = explode('/', $router->ip_address)[0] ?? null;
        $routerName = $router->name;

        $routerConfig = RouterConfig::where('router_id', $routerId)
            ->where('config_type', 'service')
            ->first();

        if (!$routerConfig || empty(trim($routerConfig->config_content))) {
            Log::error('No valid service configuration found in database for router:', [
                'router_id' => $routerId,
                'router_name' => $routerName,
            ]);
            return response()->json(['error' => 'No valid service configuration found in database'], 400);
        }

        $serviceScript = trim($routerConfig->config_content);

        Log::info('Service script retrieved from database:', [
            'router_id' => $routerId,
            'router_name' => $routerName,
            'service_script' => $serviceScript,
        ]);

        if (!$this->validateRouterOsScript($serviceScript)) {
            Log::error('Service script validation failed:', [
                'router_id' => $routerId,
                'router_name' => $routerName,
                'service_script' => $serviceScript,
            ]);
            return response()->json(['error' => 'Invalid RouterOS script syntax in configuration'], 400);
        }

        try {
            $client = null;
            $host = $initialHost;
            if ($initialHost) {
                try {
                    $client = new Client([
                        'host' => $initialHost,
                        'user' => $router->username,
                        'pass' => Crypt::decrypt($router->password),
                        'port' => $router->port ?? 8728,
                        'timeout' => 10,
                        'socket_timeout' => 10,
                    ]);
                } catch (ClientException|ConfigException $e) {
                    Log::warning('Initial IP connection failed, attempting discovery:', [
                        'router_id' => $routerId,
                        'initial_host' => $initialHost,
                        'error' => $e->getMessage(),
                    ]);
                    $host = null;
                }
            }

            if (!$client) {
                $host = $this->discoverRouterIp($routerName);
                if (!$host) {
                    throw new \Exception('Could not discover router IP for ' . $routerName);
                }
                $client = new Client([
                    'host' => $host,
                    'user' => $router->username,
                    'pass' => Crypt::decrypt($router->password),
                    'port' => $router->port ?? 8728,
                    'timeout' => 10,
                    'socket_timeout' => 10,
                ]);
            }

            $ipQuery = new Query('/ip/address/print');
            $ipQuery->where('interface', 'ether2');
            $ipResponse = $client->query($ipQuery)->read();
            $currentIp = isset($ipResponse[0]['address']) ? $ipResponse[0]['address'] : null;

            if ($currentIp && $currentIp !== $router->ip_address) {
                $router->update(['ip_address' => $currentIp]);
                Log::info('Router IP updated in database:', [
                    'router_id' => $routerId,
                    'old_ip' => $router->ip_address,
                    'new_ip' => $currentIp,
                ]);
                $host = explode('/', $currentIp)[0];
                $client = new Client([
                    'host' => $host,
                    'user' => $router->username,
                    'pass' => Crypt::decrypt($router->password),
                    'port' => $router->port ?? 8728,
                    'timeout' => 30,
                    'socket_timeout' => 30,
                ]);
            } elseif (!$currentIp) {
                throw new \Exception('Failed to retrieve current IP address from router');
            }

            $resourceQuery = new Query('/system/resource/print');
            $resource = $client->query($resourceQuery)->read();
            $freeSpace = $resource[0]['free-hdd-space'] ?? 0;
            if ($freeSpace < 5 * 1024 * 1024) {
                throw new \Exception('Insufficient disk space: ' . $freeSpace . ' bytes');
            }

            $fileQuery = new Query('/file/print');
            $fileQuery->where('name', 'hotspot_config_*.rsc');
            $files = $client->query($fileQuery)->read();
            foreach ($files as $file) {
                if (isset($file['.id'])) {
                    $removeQuery = new Query('/file/remove');
                    $removeQuery->add('=.id=' . $file['.id']);
                    $client->query($removeQuery)->read();
                }
            }

            $fileName = 'hotspot_config_' . time() . '.rsc';

            $createFileQuery = new Query('/file/add');
            $createFileQuery->add('=name=' . $fileName);
            $client->query($createFileQuery)->read();

            $fileCheckQuery = new Query('/file/print');
            $fileCheckQuery->where('name', $fileName);
            $fileCheck = $client->query($fileCheckQuery)->read();
            if (empty($fileCheck) || !isset($fileCheck[0]['.id'])) {
                throw new \Exception('Failed to create .rsc file on router: ' . $fileName);
            }

            $fileSetQuery = new Query('/file/set');
            $fileSetQuery->add('=.id=' . $fileCheck[0]['.id']);
            $fileSetQuery->add('=contents=' . $serviceScript);
            $client->query($fileSetQuery)->read();

            $fileVerifyQuery = new Query('/file/print');
            $fileVerifyQuery->where('name', $fileName);
            $fileVerifyQuery->add('detail');
            $fileVerify = $client->query($fileVerifyQuery)->read();
            $fileContents = $fileVerify[0]['contents'] ?? '';
            if (empty(trim($fileContents))) {
                Log::error('Failed to write service script to .rsc file:', [
                    'router_id' => $routerId,
                    'file_name' => $fileName,
                    'attempted_content' => $serviceScript,
                ]);
                throw new \Exception('Failed to write service script to .rsc file: ' . $fileName);
            }
            Log::info('Verified .rsc file contents:', [
                'router_id' => $routerId,
                'file_name' => $fileName,
                'file_contents' => $fileContents,
            ]);

            $importQuery = new Query('/import');
            $importQuery->add('=file-name=' . $fileName);
            $response = $client->query($importQuery)->read();

            if (isset($response['!trap'])) {
                Log::error('Failed to import .rsc file:', [
                    'router_id' => $routerId,
                    'file_name' => $fileName,
                    'error' => json_encode($response['!trap']),
                ]);
                throw new \Exception('Import failed: ' . json_encode($response['!trap']));
            }

            // Delete the .rsc file after successful import
            $fileDeleteQuery = new Query('/file/remove');
            $fileDeleteQuery->add('=.id=' . $fileCheck[0]['.id']);
            $client->query($fileDeleteQuery)->read();
            Log::info('Configuration file deleted successfully:', [
                'router_id' => $routerId,
                'file_name' => $fileName,
            ]);

            Log::info('Configuration applied successfully for router:', [
                'router_id' => $routerId,
                'file_name' => $fileName,
                'host' => $host,
            ]);

            return response()->json([
                'message' => 'Configuration applied successfully',
                'file_name' => $fileName,
                'note' => 'The .rsc file has been deleted after successful configuration.'
            ]);
        } catch (ClientException|ConfigException|QueryException $e) {
            Log::error('RouterOS error applying configuration:', [
                'router_id' => $routerId,
                'host' => $host ?? $initialHost,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'RouterOS error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Failed to apply configuration for router:', [
                'router_id' => $routerId,
                'host' => $host ?? $initialHost,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to apply configuration: ' . $e->getMessage()], 500);
        }
    }

    private function discoverRouterIp($routerName)
    {
        try {
            $mdnsService = '_mikrotik-api._tcp.local';
            $output = [];
            $returnVar = 0;
            exec("avahi-browse -t -r -p $mdnsService 2>/dev/null | grep '=;.*;IPv4;.*$routerName.*'", $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                foreach ($output as $line) {
                    $parts = explode(';', $line);
                    if (count($parts) >= 8 && filter_var($parts[7], FILTER_VALIDATE_IP)) {
                        Log::info('Router discovered via mDNS:', [
                            'router_name' => $routerName,
                            'ip' => $parts[7],
                        ]);
                        return $parts[7];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('mDNS discovery failed:', [
                'router_name' => $routerName,
                'error' => $e->getMessage(),
            ]);
        }

        $subnet = '192.168.56.0/24';
        try {
            $ipList = $this->scanSubnetForRouter($subnet, $routerName);
            if (!empty($ipList)) {
                Log::info('Router discovered via subnet scan:', [
                    'router_name' => $routerName,
                    'ip' => $ipList[0],
                ]);
                return $ipList[0];
            }
        } catch (\Exception $e) {
            Log::warning('Subnet scan failed:', [
                'router_name' => $routerName,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function scanSubnetForRouter($subnet, $routerName)
    {
        $ipList = [];
        $baseIp = preg_replace('/\.\d+\/\d+$/', '.1', $subnet);
        $timeout = 1;

        for ($i = 1; $i <= 254; $i++) {
            $ip = str_replace('.1', '.' . $i, $baseIp);
            try {
                $client = new Client([
                    'host' => $ip,
                    'user' => $this->router->username,
                    'pass' => Crypt::decrypt($this->router->password),
                    'port' => $this->router->port ?? 8728,
                    'timeout' => $timeout,
                    'socket_timeout' => $timeout,
                ]);
                $identityQuery = new Query('/system/identity/print');
                $identity = $client->query($identityQuery)->read();
                if (isset($identity[0]['name']) && $identity[0]['name'] === $routerName) {
                    $ipList[] = $ip;
                    break;
                }
            } catch (ClientException|ConfigException $e) {
                continue;
            }
        }

        return $ipList;
    }
}