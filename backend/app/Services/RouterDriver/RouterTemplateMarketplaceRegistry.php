<?php

declare(strict_types=1);

namespace App\Services\RouterDriver;

class RouterTemplateMarketplaceRegistry
{
    public function __construct(protected RouterVendorProfileRegistry $vendorRegistry)
    {
    }

    public function all(): array
    {
        $templates = (array) config('router_templates.templates', []);

        return array_values(array_map(fn (array $template) => $this->decorate($template), array_filter($templates, static fn ($template) => is_array($template))));
    }

    public function forVendor(?string $vendor): array
    {
        $vendor = strtolower(trim((string) $vendor));
        if ($vendor === '') {
            return $this->all();
        }

        return array_values(array_filter($this->all(), static fn (array $template): bool => in_array($vendor, $template['supported_vendors'] ?? [], true)));
    }

    public function defaultTemplateId(): ?string
    {
        $id = trim((string) config('router_templates.default_template', ''));
        return $id !== '' ? $id : null;
    }

    public function get(?string $templateId): ?array
    {
        $templateId = trim((string) $templateId);
        if ($templateId === '') {
            return null;
        }

        foreach ($this->all() as $template) {
            if (($template['id'] ?? null) === $templateId) {
                return $template;
            }
        }

        return null;
    }

    private function decorate(array $template): array
    {
        $supportedVendors = array_values(array_filter(array_map('strtolower', array_map('strval', (array) ($template['supported_vendors'] ?? [])))));
        $resolvedVendors = [];
        $executionTemplateType = is_string($template['execution_template_type'] ?? null)
            ? trim((string) $template['execution_template_type'])
            : null;

        foreach ($supportedVendors as $vendor) {
            if ($this->vendorRegistry->supportsVendor($vendor)) {
                $resolvedVendors[] = $vendor;
            }
        }

        $template['supported_vendors'] = $supportedVendors;
        $template['resolved_vendors'] = array_values(array_unique($resolvedVendors));
        $template['is_multi_vendor'] = count($supportedVendors) > 1;
        $template['is_available'] = $template['resolved_vendors'] !== [];
        $template['execution_template_type'] = $executionTemplateType !== '' ? $executionTemplateType : null;
        $template['can_execute'] = $template['is_available'] && in_array($template['execution_template_type'], ['hotspot', 'pppoe', 'hybrid', 'multi-wan-failover', 'pcc-balanced'], true);
        $template['execution_mode'] = $template['can_execute'] ? 'deployable' : 'preview_only';
        $template['execution_reason'] = $template['can_execute']
            ? 'Template can be deployed through the safe rollout pipeline.'
            : 'Template is preview-only until a compatible generator is implemented.';

        return $template;
    }
}
