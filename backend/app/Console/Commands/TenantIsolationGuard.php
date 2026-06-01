<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TenantIsolationGuard extends Command
{
    protected $signature = 'tenant:isolation-guard
                            {--fail-on-findings : Exit with non-zero code when findings are detected}
                            {--strict : Fail when a configured critical file is missing or unreadable}';

    protected $description = 'Scan provisioning-critical files for high-risk tenant isolation bypass patterns.';

    public function handle(): int
    {
        $criticalFiles = (array) config('tenant_isolation.critical_files', []);
        $patterns = (array) config('tenant_isolation.forbidden_patterns', []);
        $allowlist = array_flip((array) config('tenant_isolation.allowlist', []));
        $strict = (bool) $this->option('strict');

        if (empty($criticalFiles) || empty($patterns)) {
            $this->warn('Tenant isolation guard is not configured.');
            return 0;
        }

        $findings = [];
        $missingFiles = [];

        foreach ($criticalFiles as $relativePath) {
            $absolutePath = base_path($relativePath);

            if (!is_file($absolutePath)) {
                $missingFiles[] = $relativePath;
                continue;
            }

            if (isset($allowlist[$relativePath])) {
                continue;
            }

            $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                $missingFiles[] = $relativePath;
                continue;
            }

            foreach ($lines as $lineNumber => $line) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $findings[] = [
                            'file' => $relativePath,
                            'line' => $lineNumber + 1,
                            'match' => trim($line),
                        ];
                    }
                }
            }
        }

        if (!empty($missingFiles)) {
            $this->warn('Tenant isolation guard could not read some configured critical files:');
            foreach ($missingFiles as $missingFile) {
                $this->line('- ' . $missingFile);
            }

            if ($strict) {
                $this->error('Strict mode enabled: missing/unreadable critical files are fatal.');
                return 1;
            }
        }

        if (empty($findings)) {
            $this->info('Tenant isolation guard passed. No high-risk patterns found in critical files.');
            return 0;
        }

        $this->error('Tenant isolation guard detected high-risk patterns:');
        foreach ($findings as $finding) {
            $this->line(sprintf('- %s:%d => %s', $finding['file'], $finding['line'], $finding['match']));
        }

        if ($this->option('fail-on-findings')) {
            return 1;
        }

        return 0;
    }
}
