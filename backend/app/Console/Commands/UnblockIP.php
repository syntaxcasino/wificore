<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class UnblockIP extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ddos:unblock {ip? : The IP address to unblock}
                            {--all : Unblock all IPs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unblock an IP address that was blocked by DDoS protection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $ip = $this->argument('ip');
        $all = $this->option('all');

        if ($all) {
            return $this->unblockAll();
        }

        if (!$ip) {
            $this->error('Please provide an IP address or use --all flag');
            return 1;
        }

        $blockKey = "ddos:blocked:{$ip}";
        
        if (!Cache::has($blockKey)) {
            $this->info("IP {$ip} is not currently blocked");
            return 0;
        }

        Cache::forget($blockKey);
        Cache::forget("ddos:requests:{$ip}");
        
        $this->info("✅ Successfully unblocked IP: {$ip}");
        
        return 0;
    }

    /**
     * Unblock all IPs
     */
    private function unblockAll(): int
    {
        $this->info('🔍 Searching for blocked IPs...');
        
        // Get all cache keys (this is Redis-specific)
        try {
            $redis = Cache::getRedis();
            $keys = $redis->keys('*ddos:blocked:*');
            
            if (empty($keys)) {
                $this->info('No blocked IPs found');
                return 0;
            }
            
            $count = 0;
            foreach ($keys as $key) {
                // Extract IP from key
                if (preg_match('/ddos:blocked:(.+)$/', $key, $matches)) {
                    $ip = $matches[1];
                    Cache::forget("ddos:blocked:{$ip}");
                    Cache::forget("ddos:requests:{$ip}");
                    $this->line("  Unblocked: {$ip}");
                    $count++;
                }
            }
            
            $this->info("✅ Successfully unblocked {$count} IP(s)");
            
        } catch (\Exception $e) {
            $this->error('Failed to unblock IPs: ' . $e->getMessage());
            $this->info('Note: This command requires Redis as the cache driver');
            return 1;
        }
        
        return 0;
    }
}
