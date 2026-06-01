<?php

namespace Tests\Unit\Services;

use App\Services\MikroTik\RouterOsCapabilityRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterOsCapabilityRegistryTest extends TestCase
{
    #[Test]
    public function it_maps_supported_versions_to_expected_profiles(): void
    {
        $registry = new RouterOsCapabilityRegistry();

        $p78 = $registry->resolveProfile('7.8.2');
        $p715 = $registry->resolveProfile('7.15.1');
        $p718 = $registry->resolveProfile('7.18.0');

        $this->assertTrue($p78['supported']);
        $this->assertSame('ros7_8', $p78['profile']);

        $this->assertTrue($p715['supported']);
        $this->assertSame('ros7_15', $p715['profile']);

        $this->assertTrue($p718['supported']);
        $this->assertSame('ros7_18', $p718['profile']);
    }

    #[Test]
    public function it_rejects_missing_or_unsupported_versions(): void
    {
        $registry = new RouterOsCapabilityRegistry();

        $missing = $registry->resolveProfile(null);
        $v6 = $registry->resolveProfile('6.49.10');
        $bad = $registry->resolveProfile('abc');

        $this->assertFalse($missing['supported']);
        $this->assertFalse($v6['supported']);
        $this->assertFalse($bad['supported']);
        $this->assertNotEmpty($missing['error']);
        $this->assertNotEmpty($v6['error']);
        $this->assertNotEmpty($bad['error']);
    }

    #[Test]
    public function it_exposes_capability_shape_for_profile(): void
    {
        $registry = new RouterOsCapabilityRegistry();
        $caps = $registry->capabilitiesFor('ros7_15');

        $this->assertArrayHasKey('allowed_commands', $caps);
        $this->assertArrayHasKey('required_params', $caps);
        $this->assertArrayHasKey('unsupported_params', $caps);
        $this->assertContains('/interface/bridge/add', $caps['allowed_commands']);
        $this->assertArrayHasKey('/interface/bridge/add', $caps['required_params']);
    }


    #[Test]
    public function it_builds_a_router_fingerprint_and_update_payload_from_live_data(): void
    {
        $registry = new RouterOsCapabilityRegistry();

        $fingerprint = $registry->buildFingerprint([
            'version' => '7.18.1',
            'architecture' => 'arm64',
            'board_name' => 'RB5009UG+S+',
            'vendor' => 'mikrotik',
        ]);

        $payload = $registry->buildRouterUpdatePayload([
            'version' => '7.18.1',
            'architecture' => 'arm64',
            'board_name' => 'RB5009UG+S+',
        ]);

        $this->assertTrue($fingerprint['supported']);
        $this->assertSame('ros7_18', $fingerprint['profile']);
        $this->assertSame('7.18.1', $fingerprint['version']);
        $this->assertSame('arm64', $fingerprint['architecture_name']);
        $this->assertSame('RB5009UG+S+', $fingerprint['board_name']);
        $this->assertSame('mikrotik', $fingerprint['vendor']);

        $this->assertSame('RB5009UG+S+', $payload['model']);
        $this->assertSame('7.18.1', $payload['os_version']);
        $this->assertSame('arm64', $payload['architecture_name']);
        $this->assertSame('RB5009UG+S+', $payload['board_name']);
        $this->assertArrayHasKey('capabilities', $payload);
        $this->assertArrayHasKey('allowed_commands', $payload['capabilities']);
    }

}
