<?php

namespace Tests\Unit\Services;

use App\Services\MpesaService;
use App\Models\Payment;

class MpesaServiceTest extends TenantAwareServiceTest
{
    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MpesaService();
    }

    public function test_cannot_access_other_tenant_resources()
    {
        $this->actingAs($this->userA);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not belong to this tenant');

        // Try to initiate payment with Tenant B's package
        $this->service->initiatePayment($this->packageB, '0712345678', 'AA:BB:CC:DD:EE:FF');
    }

    public function test_can_access_own_tenant_resources()
    {
        $this->actingAs($this->userA);

        $result = $this->service->initiatePayment(
            $this->packageA, 
            '0712345678', 
            'AA:BB:CC:DD:EE:FF'
        );

        $this->assertIsArray($result);
        
        // Verify payment was created with correct tenant
        $payment = Payment::latest()->first();
        $this->assertEquals($this->tenantA->id, $payment->tenant_id);
        $this->assertEquals($this->packageA->id, $payment->package_id);
    }

    public function test_validation_throws_exception_for_wrong_tenant()
    {
        $this->actingAs($this->userA);

        $this->expectException(\Exception::class);

        // Try to use package from different tenant
        $this->service->initiatePayment($this->packageB, '0712345678', 'AA:BB:CC:DD:EE:FF');
    }

    public function test_callback_validates_payment_tenant()
    {
        $this->actingAs($this->userA);

        // Create payment for Tenant B
        $paymentB = Payment::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'package_id' => $this->packageB->id,
            'transaction_id' => 'MPESA-TEST-123'
        ]);

        $this->expectException(\Exception::class);

        // Try to process callback for Tenant B's payment while logged in as Tenant A
        $this->service->handleCallback([
            'transaction_id' => 'MPESA-TEST-123',
            'status' => 'completed',
            'receipt' => 'RECEIPT123'
        ]);
    }
}
