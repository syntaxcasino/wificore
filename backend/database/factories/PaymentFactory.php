<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'phone_number'    => $this->faker->numerify('2547########'),
            'amount'          => $this->faker->randomElement([50, 100, 200]),
            'status'          => 'pending',
            'transaction_id'  => 'ws_' . strtoupper(Str::random(16)),
            'mac_address'     => $this->faker->macAddress(),
            'package_id'      => Package::factory(), // Automatically create associated package
        ];
    }
}
