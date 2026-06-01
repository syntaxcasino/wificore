<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RouterDriver\RouterTemplateMarketplaceRegistry;
use Tests\TestCase;

class RouterTemplateMarketplaceRegistryTest extends TestCase
{
    public function test_it_returns_available_templates_and_filters_by_vendor(): void
    {
        $registry = app(RouterTemplateMarketplaceRegistry::class);

        $all = $registry->all();
        $multiWan = $registry->forVendor('mikrotik');

        $this->assertNotEmpty($all);
        $this->assertNotEmpty($multiWan);
        $this->assertSame('mikrotik-default', $registry->defaultTemplateId());
        $this->assertTrue(collect($all)->contains(fn (array $template) => $template['id'] === 'multi-wan-failover'));
        $this->assertTrue(collect($multiWan)->contains(fn (array $template) => $template['id'] === 'pcc-balanced'));
        $this->assertTrue(collect($all)->firstWhere('id', 'mikrotik-default')['can_execute']);
        $this->assertTrue(collect($all)->firstWhere('id', 'multi-wan-failover')['can_execute']);
        $this->assertTrue(collect($all)->firstWhere('id', 'pcc-balanced')['can_execute']);
        $this->assertSame('deployable', collect($all)->firstWhere('id', 'mikrotik-default')['execution_mode']);
        $this->assertSame('deployable', collect($all)->firstWhere('id', 'multi-wan-failover')['execution_mode']);
        $this->assertSame('deployable', collect($all)->firstWhere('id', 'pcc-balanced')['execution_mode']);
    }
}
