<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConfigCheck extends Command
{
    protected $signature = 'config:check';
    protected $description = 'Check required environment variables and external service connections';

    public function handle()
    {
        $this->info('🔍 Checking Laravel configuration...');

        // Step 1: Required environment variables
        $requiredEnv = [
            #'APP_KEY',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'PUSHER_APP_ID',
            'PUSHER_APP_KEY',
            'PUSHER_APP_SECRET',
            'PUSHER_HOST',
            'PUSHER_PORT',
        ];

        $errors = [];

        foreach ($requiredEnv as $env) {
            if (empty(env($env))) {
                $errors[] = "Missing environment variable: {$env}";
            }
        }

        if (! empty($errors)) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            return Command::FAILURE;
        }

        $this->info('✅ All required environment variables are set.');

        // Step 2: Check Database connection
        try {
            DB::connection()->getPdo();
            $this->info('✅ Database connection successful.');
        } catch (\Throwable $e) {
            $this->error('❌ Database connection failed: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}
