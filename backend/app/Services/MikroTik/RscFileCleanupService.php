<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * RSC File Cleanup Service
 * 
 * Handles cleanup of orphaned .rsc files on MikroTik routers after deployment.
 * Prevents accumulation of temporary deployment scripts.
 */
class RscFileCleanupService
{
    protected SshExecutor $sshExecutor;
    
    public function __construct()
    {
        $this->sshExecutor = new SshExecutor();
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
        
        $result = $this->sshExecutor->executeCommand($router, $command);
        
        if ($result['success']) {
            Log::info('RSC file removed', [
                'router_id' => $router->id,
                'file' => $filename,
            ]);
            
            return [
                'success' => true,
                'files_removed' => 1,
                'removed_files' => [$filename],
            ];
        }
        
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Unknown error',
            'files_removed' => 0,
        ];
    }
    
    /**
     * Remove all orphaned deployment RSC files
     * Targets files matching pattern: svc_deploy_*.rsc
     */
    protected function removeOrphanedFiles(Router $router): array
    {
        // First, list all deployment RSC files
        $listCommand = '/file print where name~"svc_deploy_.*\\.rsc"';
        
        $listResult = $this->sshExecutor->executeCommand($router, $listCommand);
        
        if (!$listResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to list RSC files',
                'files_removed' => 0,
            ];
        }
        
        // Parse file list from output
        $files = $this->parseFileList($listResult['output'] ?? '');
        
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
            $removeResult = $this->sshExecutor->executeCommand($router, $removeCommand);
            
            if ($removeResult['success']) {
                $removedFiles[] = $file;
            } else {
                $failedFiles[] = $file;
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
        // Delay cleanup by 60 seconds to ensure deployment is complete
        dispatch(function () use ($router, $deploymentFile) {
            sleep(60);
            $this->cleanupRscFiles($router, $deploymentFile);
        })->delay(now()->addSeconds(60));
    }
}
