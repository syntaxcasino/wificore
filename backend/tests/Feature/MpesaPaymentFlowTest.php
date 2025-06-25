<?php

use App\Models\Payment;
use App\Models\Package;
use App\Services\MikrotikService;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

beforeEach(function () {
    // Fetch an existing package (you can refine the query as needed)
    $this->package = Package::query()->firstOrFail();

    // Mock Mikrotik service to avoid real router connection
    $this->mock(MikrotikService::class, function ($mock) {
        $mock->shouldReceive('createUser')->andReturn(true);
    });
});

test('it handles successful mpesa callback', function () {
    $package = $this->package;

    $payment = Payment::create([
        'phone_number' => '+254712345678',
        'amount' => $package->price,
        'package_id' => $package->id,
        'status' => 'pending',
        'mac_address' => '00:11:22:33:44:55',
        'transaction_id' => 'ws_CO_SUCCESS',
    ]);

    $payload = [
        'Body' => [
            'stkCallback' => [
                'MerchantRequestID' => 'test123',
                'CheckoutRequestID' => 'ws_CO_SUCCESS',
                'ResultCode' => 0,
                'ResultDesc' => 'Success',
            ]
        ]
    ];

    $response = postJson('/api/mpesa/callback', $payload);

    $response->assertOk();

    $this->assertDatabaseHas('payments', [
        'transaction_id' => 'ws_CO_SUCCESS',
        'status' => 'completed',
    ]);
});
