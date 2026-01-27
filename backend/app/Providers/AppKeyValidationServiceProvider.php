<?php

namespace App\Providers;

use App\Services\PasswordEncryptionService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

/**
 * APP_KEY Validation Service Provider
 * Validates APP_KEY configuration on application startup
 */
class AppKeyValidationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only validate in non-console environments or when running specific commands
        if ($this->app->runningInConsole()) {
            // Allow validation command to run without validation
            $command = $_SERVER['argv'][1] ?? null;
            if (in_array($command, ['router:validate-passwords', 'key:generate', 'config:cache'])) {
                return;
            }
        }
        
        // Validate APP_KEY configuration
        try {
            $validation = PasswordEncryptionService::validateAppKey();
            
            if (!$validation['valid']) {
                Log::critical('APP_KEY validation failed on startup', [
                    'issues' => $validation['issues'],
                    'info' => $validation['info']
                ]);
                
                // In production, log but don't crash the application
                if (config('app.env') !== 'production') {
                    throw new \RuntimeException(
                        'APP_KEY validation failed: ' . implode(', ', $validation['issues'])
                    );
                }
            } else {
                Log::debug('APP_KEY validated successfully on startup', [
                    'info' => $validation['info']
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to validate APP_KEY on startup', [
                'error' => $e->getMessage()
            ]);
            
            // Don't crash the application in production
            if (config('app.env') !== 'production') {
                throw $e;
            }
        }
    }
}
