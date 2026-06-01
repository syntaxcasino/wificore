<?php

declare(strict_types=1);

namespace App\Services\MikroTik;

/**
 * Common interface for MikroTik API clients.
 *
 * Implemented by:
 *   - MikroTikRestApiService  (HTTP/JSON REST, ROS 7+)
 *   - MikroTikBinaryApiService (TCP binary protocol port 8728, all ROS versions)
 *
 * Both services expose the same high-level provisioning methods so that the
 * ApiConfigurators (PppoeApiConfigurator, HotspotApiConfigurator,
 * HybridApiConfigurator) are independent of the underlying transport.
 */
interface MikroTikApiInterface
{
    public function testConnection(): bool;

    /**
     * Open a persistent connection for a batch of provisioning commands.
     * No-op for stateless transports (e.g. REST API).
     */
    public function connect(): void;

    /**
     * Close the persistent connection after provisioning is complete.
     * No-op for stateless transports.
     */
    public function disconnect(): void;

    public function createBridge(string $name, ?string $comment = null): array;
    public function removeBridge(string $name): bool;

    public function addBridgePort(string $bridge, string $interface, ?string $comment = null): array;
    public function removeBridgePort(string $interface): bool;

    public function addVlan(string $name, int $vlanId, string $interface, ?string $comment = null): array;
    public function removeVlan(string $name): bool;

    public function addInterfaceListMember(string $list, string $interface): array;

    /**
     * Create or update a resource using a set of exact-match filters.
     *
     * If a matching record already exists, the implementation updates it with
     * the provided payload instead of issuing a duplicate add.
     *
     * The return payload should be the post-operation RouterOS record so callers
     * can safely reuse it in audit and rollback ledgers.
     */
    public function upsertResource(string $endpoint, array $matchFilters, array $data): array;

    public function createPppoeServer(
        string $serviceName,
        string $interface,
        string $profile,
        int    $maxMtu            = 1480,
        int    $maxMru            = 1480,
        bool   $oneSessionPerHost = true,
        int    $keepaliveTimeout  = 30,
        string $authentication    = 'chap,mschap2'
    ): array;
    public function removePppoeServer(string $serviceName): bool;
    public function pppoeServerExists(string $serviceName): bool;

    public function addRadiusServer(
        string  $service,
        string  $address,
        string  $secret,
        int     $timeout = 3,
        ?string $comment = null
    ): array;
    public function removeRadiusByComment(string $commentPattern): void;

    public function addFirewallFilterRule(array $params): array;
    public function removeFirewallFilterByComment(string $commentPattern): void;

    public function addNatRule(array $params): array;
    public function removeNatByComment(string $comment): void;

    public function setConnectionTracking(int $tcpEstablishedTimeout = 3600, int $udpTimeout = 30): array;

    public function executeCommand(string $endpoint, array $params = []): array;
    public function fetch(string $endpoint): array;
}
