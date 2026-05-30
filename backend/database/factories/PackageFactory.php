<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'type'           => $this->faker->randomElement(['pppoe', 'hotspot', 'bundle', 'trial']),
            'name'           => $this->faker->words(2, true),
            'description'    => $this->faker->sentence(),
            'price'          => $this->faker->randomFloat(2, 10, 200),
            'duration'       => (string) $this->faker->numberBetween(1, 48),
            'upload_speed'   => $this->faker->randomElement(['5M', '10M', '20M']),
            'download_speed' => $this->faker->randomElement(['5M', '10M', '20M']),
            'speed'          => '10M/10M',
            'devices'        => $this->faker->numberBetween(1, 5),
            'is_active'      => true,
            'is_public'      => true,
        ];
    }
}


