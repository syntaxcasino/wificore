<?php

namespace App\Console\Commands;

use App\Models\SystemLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedRouterEvents extends Command
{
    protected $signature = 'routers:seed-events';

    protected $description = 'Backfill a router_imported event for every existing router so Device Events tab shows data';

    public function handle(): int
    {
        $rows = DB::table('public.router_tenant_map')
            ->select('router_id', 'tenant_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No routers found in router_tenant_map.');
            return 0;
        }

        $now = now();
        $inserted = 0;

        foreach ($rows as $row) {
            $alreadyExists = SystemLog::withoutGlobalScopes()
                ->where('entity_type', 'router')
                ->where('entity_id', $row->router_id)
                ->where('action', 'router_imported')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            SystemLog::withoutGlobalScopes()->create([
                'tenant_id'   => $row->tenant_id,
                'user_id'     => null,
                'category'    => 'router_event',
                'action'      => 'router_imported',
                'entity_type' => 'router',
                'entity_id'   => $row->router_id,
                'level'       => 'info',
                'description' => 'Router imported into event tracking system',
                'details'     => json_encode(['timestamp' => $now->toIso8601String(), 'source' => 'backfill']),
                'ip_address'  => null,
                'user_agent'  => null,
            ]);

            $inserted++;
        }

        $this->info("Seeded {$inserted} router_imported events (skipped " . ($rows->count() - $inserted) . " already existing).");
        return 0;
    }
}
