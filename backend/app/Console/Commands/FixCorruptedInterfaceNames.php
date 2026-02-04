<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\RouterService;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixCorruptedInterfaceNames extends Command
{
    protected $signature = 'router:fix-interface-names {--dry-run : Show what would be fixed without making changes}';
    protected $description = 'Fix corrupted interface_name fields in router_services table';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $tenantContext = app(TenantContext::class);
        $fixedCount = 0;
        $errorCount = 0;

        $this->info($dryRun ? 'DRY RUN - No changes will be made' : 'Fixing corrupted interface names...');

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} ({$tenant->schema_name})");

            $tenantContext->runInTenantContext($tenant, function () use ($dryRun, &$fixedCount, &$errorCount) {
                $services = RouterService::whereNotNull('interface_name')->get();

                foreach ($services as $service) {
                    $original = $service->interface_name;
                    $cleaned = $this->cleanInterfaceName($original);

                    if ($cleaned !== $original) {
                        $this->line("  Service {$service->id}:");
                        $this->line("    Original: " . json_encode($original));
                        $this->line("    Cleaned:  " . json_encode($cleaned));

                        if (!$dryRun) {
                            try {
                                // Use raw update to avoid model casting issues
                                DB::table('router_services')
                                    ->where('id', $service->id)
                                    ->update([
                                        'interface_name' => is_array($cleaned) ? json_encode($cleaned) : $cleaned,
                                        'updated_at' => now(),
                                    ]);
                                $this->info("    ✓ Fixed");
                                $fixedCount++;
                            } catch (\Exception $e) {
                                $this->error("    ✗ Error: " . $e->getMessage());
                                $errorCount++;
                            }
                        } else {
                            $this->info("    → Would fix");
                            $fixedCount++;
                        }
                    }
                }
            });
        }

        $this->newLine();
        $this->info("Summary: {$fixedCount} records " . ($dryRun ? 'would be fixed' : 'fixed') . ", {$errorCount} errors");

        return $errorCount > 0 ? 1 : 0;
    }

    private function cleanInterfaceName($value): array
    {
        $interfaces = [];

        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $decoded = json_decode($value, true);
            $items = is_array($decoded) ? $decoded : [$value];
        } else {
            return [];
        }

        foreach ($items as $item) {
            $this->extractInterfaces($item, $interfaces);
        }

        // Filter to only valid interface names
        $interfaces = array_values(array_unique(array_filter($interfaces, function ($i) {
            return is_string($i) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', trim($i));
        })));

        return $interfaces;
    }

    private function extractInterfaces($item, array &$interfaces): void
    {
        if (is_string($item)) {
            // Try JSON decode
            $decoded = json_decode($item, true);
            if (is_array($decoded)) {
                foreach ($decoded as $sub) {
                    $this->extractInterfaces($sub, $interfaces);
                }
            } elseif (str_contains($item, ',')) {
                // Comma-separated
                foreach (explode(',', $item) as $part) {
                    $trimmed = trim($part);
                    if (!empty($trimmed) && $trimmed[0] !== '[') {
                        $interfaces[] = $trimmed;
                    }
                }
            } elseif (!empty(trim($item)) && $item[0] !== '[') {
                $interfaces[] = trim($item);
            }
        } elseif (is_array($item)) {
            foreach ($item as $sub) {
                $this->extractInterfaces($sub, $interfaces);
            }
        }
    }
}
