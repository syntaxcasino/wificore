<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * RSC File Cleanup Service
 * 
 * Handles cleanup of orphaned .rsc files on MikroTik routers after deployment.
 * Prevents accumulation of temporary deployment scripts.
 */
class RscFileCleanupService
{
    private function getExecutor(Router $router, int $timeout = 10): SshExecutor
    {
        return app()->make(SshExecutor::class, [
            'router' => $router,
            'timeout' => $timeout,
        ]);
    }
    
    /**
     * Clean up orphaned RSC files on a router
     * 
     * @param Router $router The router to clean
     * @param string|null $specificFile Optional specific file to remove
     * @return array Cleanup results
     */
    public function cleanupRscFiles(Router $router, ?string $specificFile = null): array
    {
        try {
            Log::info('Starting RSC file cleanup', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'specific_file' => $specificFile,
            ]);
            
            if ($specificFile) {
                return $this->removeSpecificFile($router, $specificFile);
            }
            
            return $this->removeOrphanedFiles($router);
            
        } catch (\Exception $e) {
            Log::error('RSC cleanup failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'files_removed' => 0,
            ];
        }
    }
    
    /**
     * Remove a specific RSC file
     */
    protected function removeSpecificFile(Router $router, string $filename): array
    {
        $command = sprintf('/file remove [find name="%s"]', $filename);

        $ssh = $this->getExecutor($router);
        $ssh->connect();

        try {
            $ssh->exec($command);

            Log::info('RSC file removed', [
                'router_id' => $router->id,
                'file' => $filename,
            ]);
            
            return [
                'success' => true,
                'files_removed' => 1,
                'removed_files' => [$filename],
            ];
        } catch (
            \Exception $e
        ) {
            Log::warning('RSC file removal failed', [
                'router_id' => $router->id,
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'files_removed' => 0,
            ];
        } finally {
            $ssh->disconnect();
        }
    }
    
    /**
     * Remove all orphaned deployment RSC files
     * Targets files matching pattern: svc_deploy_*.rsc
     */
    protected function removeOrphanedFiles(Router $router): array
    {
        // First, list all deployment RSC files
        $listCommand = '/file print where name~"svc_deploy_.*\\.rsc"';

        $ssh = $this->getExecutor($router);
        $ssh->connect();
        try {
            $output = $ssh->exec($listCommand);

            // Parse file list from output
            $files = $this->parseFileList($output ?? '');

            if (empty($files)) {
                Log::info('No orphaned RSC files found', [
                    'router_id' => $router->id,
                ]);

                return [
                    'success' => true,
                    'files_removed' => 0,
                    'message' => 'No orphaned files found',
                ];
            }

            // Remove each file
            $removedFiles = [];
            $failedFiles = [];

            foreach ($files as $file) {
                $removeCommand = sprintf('/file remove [find name="%s"]', $file);
                try {
                    $ssh->exec($removeCommand);
                    $removedFiles[] = $file;
                } catch (
                    \Exception $e
                ) {
                    $failedFiles[] = $file;
                    Log::warning('Failed to remove RSC file', [
                        'router_id' => $router->id,
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('RSC cleanup completed', [
                'router_id' => $router->id,
                'removed_count' => count($removedFiles),
                'failed_count' => count($failedFiles),
                'removed_files' => $removedFiles,
            ]);

            return [
                'success' => count($failedFiles) === 0,
                'files_removed' => count($removedFiles),
                'removed_files' => $removedFiles,
                'failed_files' => $failedFiles,
            ];
        } catch (
            \Exception $e
        ) {
            Log::warning('Failed to list RSC files for cleanup', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'files_removed' => 0,
            ];
        } finally {
            $ssh->disconnect();
        }
    }
    
    /**
     * Parse file list from MikroTik output
     */
    protected function parseFileList(string $output): array
    {
        $files = [];
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            // Match lines containing svc_deploy_*.rsc
            if (preg_match('/svc_deploy_[a-zA-Z0-9_]+\.rsc/', $line, $matches)) {
                $files[] = $matches[0];
            }
        }
        
        return array_unique($files);
    }
    
    /**
     * Schedule cleanup after deployment
     * Can be called after successful service deployment
     */
    public function scheduleCleanup(Router $router, string $deploymentFile): void
    {
        // Capture scalars only — never serialize tenant-scoped models into queue closures
        $routerId = $router->id;
        $tenantId = app(TenantContext::class)->getTenantId();

        // Delay cleanup by 60 seconds to ensure deployment is complete
        dispatch(function () use ($routerId, $tenantId, $deploymentFile) {
            $tenantContext = app(TenantContext::class);
            
            // Wrap in transaction for proper SET LOCAL search_path handling with PgBouncer
            \Illuminate\Support\Facades\DB::transaction(function () use ($routerId, $tenantId, $deploymentFile, $tenantContext) {
                // Force sticky-write PDO so SET LOCAL search_path and all subsequent
                // queries target the same write backend connection under PgBouncer.
                \Illuminate\Support\Facades\DB::connection()->recordsHaveBeenModified();
                if ($tenantId) {
                    $tenantContext->setTenantById($tenantId);
                }

                try {
                    // Now load the router - tenant context is already set
                    $router = Router::on('pgsql')->useWritePdo()->find($routerId);
                    if (!$router) {
                        Log::warning('RSC cleanup: Router not found', ['router_id' => $routerId]);
                        return;
                    }

                    $service = new self();
                    $service->cleanupRscFiles($router, $deploymentFile);
                } finally {
                    if ($tenantId) {
                        $tenantContext->clearTenant();
                    }
                }
            });
        })->delay(now()->addSeconds(60));
    }
}
