<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MikrotikProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Events\RouterProvisioningProgress;
use App\Events\ProvisioningFailed;

class TestProvisioningWithEvents extends Command
{
    protected $signature = 'provisioning:test {router_id} {--script=}';
    protected $description = 'Test the provisioning process with event broadcasting';

    public function handle(MikrotikProvisioningService $provisioningService)
    {
        $routerId = $this->argument('router_id');
        $router = Router::findOrFail($routerId);
        $script = $this->option('script');

        $this->info("Starting provisioning test for router: {$router->name} (ID: {$router->id})");
        
        // Listen for provisioning events
        $this->listenForEvents();

        try {
            $result = $provisioningService->applyConfigs($router, $script);
            $this->info("\n✅ Provisioning completed successfully!");
            $this->line("Execution time: {$result['execution_time']}");
            $this->line("Script: {$result['script_name']}");
            
            $this->info("\nVerification results:");
            $this->table(
                ['Check', 'Status', 'Message'],
                collect($result['verification']['checks'] ?? [])->map(function ($check, $key) {
                    return [
                        'check' => $key,
                        'status' => $check['status'] ? '✅' : '❌',
                        'message' => $check['message']
                    ];
                })
            );
            
            return 0;
        } catch (\Exception $e) {
            $this->error("\n❌ Provisioning failed: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . ':' . $e->getLine());
            
            if ($e->getPrevious()) {
                $this->error("Previous: " . $e->getPrevious()->getMessage());
            }
            
            return 1;
        }
    }

    protected function listenForEvents()
    {
        RouterProvisioningProgress::listen(function ($event) {
            $this->line("");
            $this->line("🔄 [{$event->stage}] {$event->message}");
            $this->line("   Progress: [" . str_repeat('=', (int)($event->progress/5)) . ">" . 
                        str_repeat(' ', 20 - (int)($event->progress/5)) . "] {$event->progress}%");
            
            if (!empty($event->data)) {
                $this->line("   Details: " . json_encode($event->data));
            }
        });

        ProvisioningFailed::listen(function ($event) {
            $this->error("\n❌ [FAILED] {$event->stage}: {$event->message}");
            
            if (!empty($event->data)) {
                $this->line("   Details: " . json_encode($event->data));
            }
        });
    }
}
