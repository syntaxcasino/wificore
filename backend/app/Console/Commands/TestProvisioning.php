<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\RouterProvisioningJob;
use App\Models\Router;

class TestProvisioning extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:provisioning {routerId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test provisioning for a router';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $routerId = $this->argument('routerId');
        $router = Router::find($routerId);
        
        if (!$router) {
            $this->error("Router not found");
            return 1;
        }
        
        RouterProvisioningJob::dispatch($router, [
            "service_type" => "hotspot",
            "hotspot_interfaces" => ["ether2"]
        ]);
        
        $this->info("Provisioning job dispatched for router {$routerId}");
        
        return 0;
    }
}
