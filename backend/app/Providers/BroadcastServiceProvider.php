<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Broadcast;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // DISABLED: We use a custom broadcasting auth route in routes/api.php
        // that properly sets the user resolver for Sanctum authentication
        // 
        // Broadcast::routes([
        //     'middleware' => ['auth:sanctum'],
        //     'prefix' => 'api',
        //     'as' => 'api.broadcasting.auth',
        // ]);

        // Only load channel definitions, not the auth routes
        require base_path('routes/channels.php');
    }
}
