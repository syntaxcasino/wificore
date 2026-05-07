<?php

namespace App\Console\Commands;

use App\Models\PppoeUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SetupPppoePortalPasswords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pppoe:setup-portal-passwords 
                            {--default= : Default password to set (default: account_number)}
                            {--generate : Generate random passwords and output to file}
                            {--tenant= : Only process specific tenant ID}
                            {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up portal passwords for PPPoE users who dont have one';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $defaultPassword = $this->option('default') ?? 'account_number';
        $generateRandom = $this->option('generate');
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $query = PppoeUser::query()
            ->whereNull('portal_password');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('All PPPoE users already have portal passwords set.');
            return self::SUCCESS;
        }

        $this->info("Found {$count} PPPoE users without portal passwords.");

        if ($dryRun) {
            $this->warn('DRY RUN: No changes will be made.');
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updatedCount = 0;
        $passwords = [];

        $query->chunk(100, function ($users) use ($defaultPassword, $generateRandom, $dryRun, &$updatedCount, &$bar, &$passwords) {
            foreach ($users as $user) {
                if ($generateRandom) {
                    $plainPassword = Str::random(8);
                    $passwords[] = [
                        'account_number' => $user->account_number,
                        'username' => $user->username,
                        'portal_password' => $plainPassword,
                    ];
                } else {
                    $plainPassword = $defaultPassword === 'account_number' 
                        ? $user->account_number 
                        : $defaultPassword;
                }

                if (!$dryRun) {
                    $user->setPortalPassword($plainPassword);
                    $user->save();
                }

                $updatedCount++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        if ($generateRandom && !empty($passwords)) {
            $filename = storage_path('app/pppoe_portal_passwords_' . now()->format('Y-m-d_His') . '.csv');
            $handle = fopen($filename, 'w');
            fputcsv($handle, ['Account Number', 'Username', 'Portal Password']);
            
            foreach ($passwords as $row) {
                fputcsv($handle, $row);
            }
            
            fclose($handle);
            $this->info("Passwords saved to: {$filename}");
            $this->warn('IMPORTANT: Store this file securely and delete after distribution!');
        }

        $this->info("Successfully {$dryRun ? 'would update' : 'updated'} {$updatedCount} users.");
        
        if (!$generateRandom && !$dryRun) {
            $this->warn("Portal passwords are set to: {$defaultPassword}");
            $this->warn('Users should change their passwords after first login.');
        }

        return self::SUCCESS;
    }
}
