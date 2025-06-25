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
            'name'             => $this->faker->words(2, true), // e.g. "Silver Plan"
           'description'      => $this->faker->sentence(),
           'price'            => $this->faker->randomFloat(2, 10, 200), // float value
           'duration_hours'   => $this->faker->numberBetween(1, 48),
           'mikrotik_profile' => $this->faker->word(), // should match actual Mikrotik profile names in prod
        ];
    }
}


