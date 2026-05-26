<?php

namespace App\Contracts;

use App\Models\Router;
use App\Models\RouterTask;

interface ProvisioningCommandBus
{
    public function callbacksEnabled(?RouterTask $task = null, bool $terminal = true, ?string $stage = null): bool;

    public function submitRouterServiceDeploymentCommand(Router $router, string $tenantId, string $serviceId, string $script, ?string $requestedBy = null): array;

    public function submitVpnConnectivityWaitCommand(string $tenantId, array $payload): array;

    public function submitLiveDataRefreshCommand(string $tenantId, array $payload): array;

    public function submitRouterStatusRefreshCommand(string $tenantId, array $payload): array;

    public function submitVpnStatusRefreshCommand(string $tenantId, array $payload): array;

    public function submitRouterMetricsCommand(string $tenantId, array $payload): array;

    public function submitTaskCommand(Router $router, string $tenantId, string $type, array $payload = [], ?RouterTask $task = null): array;
}
