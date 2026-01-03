<?php

namespace App\Providers;

use App\Listeners\TrackCompletedJobs;
use App\Models\PersonalAccessToken;
use App\Services\RadiusService;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RadiusService::class, function ($app) {
            return new RadiusService();
        });
        
        // Register TenantContext as singleton to maintain state across middleware and controllers
        $this->app->singleton(\App\Services\TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        
        // Track completed jobs for statistics
        Event::listen(JobProcessed::class, TrackCompletedJobs::class);
    }
}
