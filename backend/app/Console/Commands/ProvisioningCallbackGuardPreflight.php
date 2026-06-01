<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ProvisioningCallbackGuardPreflight extends Command
{
    protected $signature = 'provisioning:callback-guard-preflight
                            {--strict : Return non-zero on warnings}
                            {--probe-provisioning-date : Probe provisioning service Date header for clock skew check}
                            {--max-probe-skew-seconds=5 : Maximum allowed skew (seconds) for probe check}';

    protected $description = 'Validate callback guard config and rollout readiness before enforcing strict callback identity/stale rejection.';

    public function handle(): int
    {
        $warnings = [];
        $errors = [];

        $requireIdentity = (bool) config('services.provisioning.require_callback_identity', false);
        $warnMissingIdentity = (bool) config('services.provisioning.warn_on_missing_callback_identity', true);
        $rejectStale = (bool) config('services.provisioning.reject_stale_callbacks', false);
        $warnStale = (bool) config('services.provisioning.warn_on_stale_callbacks', true);
        $maxSkew = (int) config('services.provisioning.max_callback_skew_seconds', 900);
        $provisioningUrl = rtrim((string) config('services.provisioning.url', ''), '/');

        $this->line('Provisioning callback guard preflight:');
        $this->line('- require_callback_identity=' . ($requireIdentity ? 'true' : 'false'));
        $this->line('- warn_on_missing_callback_identity=' . ($warnMissingIdentity ? 'true' : 'false'));
        $this->line('- reject_stale_callbacks=' . ($rejectStale ? 'true' : 'false'));
        $this->line('- warn_on_stale_callbacks=' . ($warnStale ? 'true' : 'false'));
        $this->line('- max_callback_skew_seconds=' . $maxSkew);

        if ($maxSkew <= 0) {
            $errors[] = 'services.provisioning.max_callback_skew_seconds must be greater than 0.';
        } elseif ($maxSkew < 30) {
            $warnings[] = 'max_callback_skew_seconds is very low (<30s); this may cause false stale rejects.';
        }

        if ($rejectStale && ! $warnStale) {
            $warnings[] = 'reject_stale_callbacks=true while warn_on_stale_callbacks=false reduces observability.';
        }

        if ($requireIdentity && ! $warnMissingIdentity) {
            $warnings[] = 'require_callback_identity=true while warn_on_missing_callback_identity=false may hide rollout issues.';
        }

        if ($this->option('probe-provisioning-date')) {
            if ($provisioningUrl === '') {
                $errors[] = 'services.provisioning.url is empty; cannot probe provisioning service clock skew.';
            } else {
                $probeWarnings = $this->probeProvisioningDateHeader($provisioningUrl, (int) $this->option('max-probe-skew-seconds'));
                array_push($warnings, ...$probeWarnings);
            }
        }

        foreach ($warnings as $warning) {
            $this->warn('[WARN] ' . $warning);
        }

        foreach ($errors as $error) {
            $this->error('[ERROR] ' . $error);
        }

        if (! empty($errors)) {
            return 1;
        }

        if ((bool) $this->option('strict') && ! empty($warnings)) {
            $this->error('Preflight strict mode failed due to warnings.');
            return 1;
        }

        $this->info('Provisioning callback guard preflight passed.');

        return 0;
    }

    /**
     * @return array<int, string>
     */
    private function probeProvisioningDateHeader(string $provisioningUrl, int $maxProbeSkewSeconds): array
    {
        $warnings = [];
        $maxProbeSkewSeconds = max(1, $maxProbeSkewSeconds);

        try {
            $response = Http::timeout(5)->get($provisioningUrl . '/health');
        } catch (\Throwable $e) {
            return ['Failed to probe provisioning service Date header: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['Provisioning service health probe returned non-success HTTP status: ' . $response->status()];
        }

        $dateHeader = (string) $response->header('Date', '');
        if ($dateHeader === '') {
            return ['Provisioning service Date header is missing; cannot validate cross-service clock skew.'];
        }

        $remoteTs = strtotime($dateHeader);
        if ($remoteTs === false) {
            return ['Provisioning service Date header is invalid: ' . $dateHeader];
        }

        $skew = abs(time() - $remoteTs);
        $this->line('- provisioning_date_header_skew_seconds=' . $skew);

        if ($skew > $maxProbeSkewSeconds) {
            $warnings[] = sprintf(
                'Provisioning service clock skew (%ds) exceeds threshold (%ds).',
                $skew,
                $maxProbeSkewSeconds
            );
        }

        return $warnings;
    }
}
