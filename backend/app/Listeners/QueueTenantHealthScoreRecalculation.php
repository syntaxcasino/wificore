<?php

namespace App\Listeners;

use App\Jobs\UpdateTenantHealthScoreJob;

class QueueTenantHealthScoreRecalculation
{
    public function handle(object $event): void
    {
        $tenantId = $event->tenantId ?? null;
        if (! is_string($tenantId) || $tenantId === '') {
            return;
        }

        UpdateTenantHealthScoreJob::dispatch($tenantId, [
            'source_event' => class_basename($event),
            'source_reference' => $this->referenceFromEvent($event),
        ])->onQueue('dashboard');
    }

    private function referenceFromEvent(object $event): ?string
    {
        foreach (['routerId', 'paymentId', 'vpnConfigId'] as $property) {
            if (isset($event->{$property}) && is_scalar($event->{$property})) {
                return (string) $event->{$property};
            }
        }

        return null;
    }
}
