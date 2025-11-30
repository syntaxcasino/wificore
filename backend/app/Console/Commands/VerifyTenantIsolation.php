<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class VerifyTenantIsolation extends Command
{
    protected $signature = 'tenant:verify-isolation';
    protected $description = 'Verify tenant isolation in all routes';

    public function handle()
    {
        $this->info('🔍 Verifying Tenant Isolation...');
        $this->newLine();

        $routes = Route::getRoutes();
        $tenantRoutes = [];
        $systemRoutes = [];
        $publicRoutes = [];
        $issues = [];

        foreach ($routes as $route) {
            $uri = $route->uri();
            $name = $route->getName();
            $middleware = $route->middleware();
            $action = $route->getActionName();

            // Skip non-API routes
            if (!Str::startsWith($uri, 'api/')) {
                continue;
            }

            // Categorize routes
            if (Str::contains($uri, '/system/') || Str::contains($name ?? '', 'system')) {
                $systemRoutes[] = [
                    'uri' => $uri,
                    'name' => $name,
                    'middleware' => $middleware,
                    'action' => $action
                ];
            } elseif (Str::contains($uri, '/tenant/')) {
                $tenantRoutes[] = [
                    'uri' => $uri,
                    'name' => $name,
                    'middleware' => $middleware,
                    'action' => $action
                ];
            } elseif (Str::contains($uri, '/public/')) {
                $publicRoutes[] = [
                    'uri' => $uri,
                    'name' => $name,
                    'middleware' => $middleware,
                    'action' => $action
                ];
            } else {
                // Check if route should be tenant-scoped
                if ($this->shouldBeTenantScoped($uri, $middleware)) {
                    $issues[] = [
                        'uri' => $uri,
                        'name' => $name,
                        'issue' => 'Missing tenant.context middleware'
                    ];
                }
            }
        }

        // Display summary
        $this->table(
            ['Category', 'Count'],
            [
                ['System Admin Routes', count($systemRoutes)],
                ['Tenant Routes', count($tenantRoutes)],
                ['Public Routes', count($publicRoutes)],
                ['Potential Issues', count($issues)],
            ]
        );

        $this->newLine();

        // Display system admin routes
        if (count($systemRoutes) > 0) {
            $this->info('📋 System Admin Routes:');
            foreach ($systemRoutes as $route) {
                $hasSystemMiddleware = in_array('system.admin', $route['middleware']) || 
                                      in_array('role:system_admin', $route['middleware']);
                $status = $hasSystemMiddleware ? '✅' : '❌';
                $this->line("  {$status} {$route['uri']} ({$route['name']})");
            }
            $this->newLine();
        }

        // Display tenant routes
        if (count($tenantRoutes) > 0) {
            $this->info('📋 Tenant Routes:');
            foreach (array_slice($tenantRoutes, 0, 10) as $route) {
                $hasTenantMiddleware = in_array('tenant.context', $route['middleware']);
                $status = $hasTenantMiddleware ? '✅' : '❌';
                $this->line("  {$status} {$route['uri']} ({$route['name']})");
            }
            if (count($tenantRoutes) > 10) {
                $this->line("  ... and " . (count($tenantRoutes) - 10) . " more");
            }
            $this->newLine();
        }

        // Display issues
        if (count($issues) > 0) {
            $this->error('⚠️  Potential Tenant Isolation Issues:');
            foreach ($issues as $issue) {
                $this->line("  ❌ {$issue['uri']} - {$issue['issue']}");
            }
            $this->newLine();
            return 1;
        }

        $this->info('✅ All routes appear to have proper tenant isolation!');
        return 0;
    }

    private function shouldBeTenantScoped($uri, $middleware)
    {
        // Routes that should be tenant-scoped
        $tenantResources = [
            'packages',
            'routers',
            'payments',
            'users',
            'hotspot-users',
            'sessions',
            'vouchers',
        ];

        foreach ($tenantResources as $resource) {
            if (Str::contains($uri, $resource) && !Str::contains($uri, 'public')) {
                // Check if it has tenant middleware
                return !in_array('tenant.context', $middleware) && 
                       !in_array('system.admin', $middleware);
            }
        }

        return false;
    }
}
