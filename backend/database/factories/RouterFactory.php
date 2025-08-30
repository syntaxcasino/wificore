<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Router>
 */
class RouterFactory extends Factory
{
    protected $model = Router::class;

    public function definition()
    {
        return [
            'host' => '192.168.88.1',
            'username' => 'admin',
            'password' => '',
            'port' => 8728,
        ];
    }
}
