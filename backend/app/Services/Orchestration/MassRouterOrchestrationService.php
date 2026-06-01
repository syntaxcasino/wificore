<?php

declare(strict_types=1);

namespace App\Services\Orchestration;

use App\Jobs\DeployRouterConfigJob;
use App\Models\Router;
use App\Services\MikroTik\RouterOsCapabilityRegistry;
use App\Services\MikroTik\RouterOsV7ProvisioningValidator;
use App\Services\MikroTik\ServiceTemplateService;
use App\Services\RouterDriver\RouterTemplateMarketplaceRegistry;
use App\Services\RouterDriver\RouterVendorProfileRegistry;

class MassRouterOrchestrationService
{
    public function __construct(
        protected RouterVendorProfileRegistry $vendorRegistry,
        protected RouterOsCapabilityRegistry $capabilityRegistry,
        protected RouterTemplateMarketplaceRegistry $templateRegistry,
        protected ServiceTemplateService $templateService,
        protected RouterOsV7ProvisioningValidator $routerOsValidator,
    ) {
    }

    public function preview(array $routers, array $options = []): array
    {
        $changeType = (string) ($options['change_type'] ?? 'apply_service_configs');
        $template = (string) ($options['template'] ?? 'default');
        $batchSize = max(1, (int) ($options['batch_size'] ?? 5));

        $routerPlans = [];
        $warnings = [];

        foreach (array_values($routers) as $index => $router) {
            $vendor = (string) ($router['vendor'] ?? 'mikrotik');
            $model = (string) ($router['model'] ?? '');
            $version = (string) ($router['os_version'] ?? '');
            $profile = $this->vendorRegistry->resolve($vendor, $model);
            $versionProfile = $this->capabilityRegistry->resolveProfile($version);

            $routerWarnings = [];
            if (! ($profile['supported'] ?? false)) {
                $routerWarnings[] = $profile['error'] ?? 'Vendor profile is unsupported.';
            }
            if (! ($versionProfile['supported'] ?? false)) {
                $routerWarnings[] = $versionProfile['error'] ?? 'RouterOS version is unsupported.';
            }
            if (($router['status'] ?? '') !== 'online') {
                $routerWarnings[] = 'Router is not online; defer until connectivity is stable.';
            }

            if ($routerWarnings !== []) {
                $warnings = array_merge($warnings, array_map(static fn (string $warning): string => sprintf('%s: %s', (string) ($router['name'] ?? $router['id'] ?? $index), $warning), $routerWarnings));
            }

            $routerPlans[] = [
                'router_id' => (string) ($router['id'] ?? $index),
                'name' => (string) ($router['name'] ?? 'Router ' . ($index + 1)),
                'vendor' => $profile['vendor'] ?? $vendor,
                'vendor_profile' => $profile['capability_profile'] ?? null,
                'version_profile' => $versionProfile['profile'] ?? null,
                'supported' => (bool) (($profile['supported'] ?? false) && ($versionProfile['supported'] ?? false)),
                'warnings' => $routerWarnings,
                'priority' => $this->priorityFor($router, $index),
            ];
        }

        usort($routerPlans, static function (array $a, array $b): int {
            return [$a['priority'], $a['router_id']] <=> [$b['priority'], $b['router_id']];
        });

        return [
            'change_type' => $changeType,
            'template' => $template,
            'batch_size' => $batchSize,
            'router_count' => count($routerPlans),
            'supported_count' => count(array_filter($routerPlans, static fn (array $router): bool => $router['supported'] === true)),
            'warning_count' => count($warnings),
            'warnings' => array_values(array_unique($warnings)),
            'router_plans' => $routerPlans,
            'execution_strategy' => [
                'mode' => 'preview',
                'ordering' => 'online_first_then_stable_vendor_profile',
                'batching' => $batchSize > 1 ? 'chunked' : 'sequential',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function deploy(array $routers, array $options = []): array
    {
        $templateId = trim((string) ($options['template'] ?? ''));
        $template = $this->templateRegistry->get($templateId);

        if (! is_array($template)) {
            throw new \InvalidArgumentException('Unknown router template: ' . ($templateId !== '' ? $templateId : '[empty]'));
        }

        if (! ($template['can_execute'] ?? false)) {
            throw new \InvalidArgumentException(sprintf(
                'Template "%s" is preview-only and cannot be deployed yet.',
                (string) ($template['name'] ?? $templateId)
            ));
        }

        $executionType = (string) ($template['execution_template_type'] ?? '');
        if (! in_array($executionType, ['hotspot', 'pppoe', 'hybrid', 'multi-wan-failover', 'pcc-balanced'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Template "%s" has no executable template generator.',
                (string) ($template['name'] ?? $templateId)
            ));
        }

        $routerPayloads = array_map(static fn (Router $router): array => [
            'id' => (string) $router->id,
            'name' => (string) $router->name,
            'vendor' => (string) ($router->vendor ?? 'mikrotik'),
            'model' => (string) ($router->model ?? ''),
            'os_version' => (string) ($router->os_version ?? ''),
            'status' => (string) ($router->status ?? 'unknown'),
        ], $routers);

        $preview = $this->preview($routerPayloads, $options);
        $deploymentVersion = sprintf('%s-%s', $templateId, now()->format('YmdHis'));
        $templateConfig = (array) ($options['template_config'] ?? []);
        $queued = [];
        $skipped = [];
        $warnings = $preview['warnings'] ?? [];
        $routerMap = [];

        foreach ($routers as $router) {
            if ($router instanceof Router) {
                $routerMap[(string) $router->id] = $router;
            }
        }

        foreach ($preview['router_plans'] ?? [] as $plan) {
            $routerId = (string) ($plan['router_id'] ?? '');
            $router = $routerMap[$routerId] ?? null;
            if (! $router instanceof Router) {
                $skipped[] = [
                    'router_id' => $routerId,
                    'name' => (string) ($plan['name'] ?? $routerId),
                    'reason' => 'Router record could not be resolved for deployment.',
                ];
                $warnings[] = sprintf('%s: router record could not be resolved for deployment.', (string) ($plan['name'] ?? $routerId));
                continue;
            }

            if (! ($plan['supported'] ?? false)) {
                $reason = 'Router is not eligible for executable mass deployment.';
                $skipped[] = [
                    'router_id' => $routerId,
                    'name' => (string) $router->name,
                    'reason' => $reason,
                ];
                $warnings[] = sprintf('%s: %s', (string) $router->name, $reason);
                continue;
            }

            $resolvedVendor = $this->vendorRegistry->resolveVendor($router->vendor, $router->model);
            if ($resolvedVendor !== null && $resolvedVendor !== 'mikrotik') {
                $reason = sprintf('Template generator is currently implemented for MikroTik routers only (resolved vendor: %s).', $resolvedVendor);
                $skipped[] = [
                    'router_id' => $routerId,
                    'name' => (string) $router->name,
                    'reason' => $reason,
                ];
                $warnings[] = sprintf('%s: %s', (string) $router->name, $reason);
                continue;
            }

            try {
                $script = $this->templateService->generateFromTemplate($router, $executionType, $templateConfig);

                DeployRouterConfigJob::dispatch($router, $script, sprintf('%s-%s', $deploymentVersion, $routerId), false);

                $queued[] = [
                    'router_id' => $routerId,
                    'name' => (string) $router->name,
                    'template' => $templateId,
                    'template_execution_type' => $executionType,
                    'script_length' => strlen($script),
                    'status' => 'queued',
                ];
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
                $skipped[] = [
                    'router_id' => $routerId,
                    'name' => (string) $router->name,
                    'reason' => $reason,
                ];
                $warnings[] = sprintf('%s: %s', (string) $router->name, $reason);
            }
        }

        $uniqueWarnings = array_values(array_unique(array_filter(array_map('strval', $warnings), static fn (string $warning): bool => $warning !== '')));

        return [
            'change_type' => $preview['change_type'] ?? (string) ($options['change_type'] ?? 'apply_service_configs'),
            'template' => $templateId,
            'template_name' => (string) ($template['name'] ?? $templateId),
            'template_execution_type' => $executionType,
            'template_deployable' => true,
            'deployment_version' => $deploymentVersion,
            'router_count' => count($preview['router_plans'] ?? []),
            'queued_count' => count($queued),
            'skipped_count' => count($skipped),
            'warning_count' => count($uniqueWarnings),
            'warnings' => $uniqueWarnings,
            'queued_routers' => $queued,
            'skipped_routers' => $skipped,
            'preview' => $preview,
            'execution_strategy' => [
                'mode' => 'deploy',
                'ordering' => $preview['execution_strategy']['ordering'] ?? 'online_first_then_stable_vendor_profile',
                'batching' => 'queued_jobs',
                'job' => DeployRouterConfigJob::class,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function priorityFor(array $router, int $index): int
    {
        $status = strtolower((string) ($router['status'] ?? 'unknown'));
        $statusWeight = match ($status) {
            'online' => 0,
            'rebooting' => 1,
            'unknown' => 2,
            default => 3,
        };

        $vendorWeight = strtolower((string) ($router['vendor'] ?? 'mikrotik')) === 'mikrotik' ? 0 : 1;

        return ($statusWeight * 100) + ($vendorWeight * 10) + $index;
    }
}
