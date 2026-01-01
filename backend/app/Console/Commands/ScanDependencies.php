<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScanDependencies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:scan-dependencies {--report : Generate detailed report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan PHP and JavaScript dependencies for known vulnerabilities';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting dependency vulnerability scan...');
        $this->newLine();
        
        $vulnerabilities = [];
        
        // Scan PHP dependencies
        $this->info('Scanning PHP dependencies (Composer)...');
        $phpVulnerabilities = $this->scanComposerDependencies();
        $vulnerabilities = array_merge($vulnerabilities, $phpVulnerabilities);
        
        // Scan JavaScript dependencies
        $this->info('Scanning JavaScript dependencies (NPM)...');
        $jsVulnerabilities = $this->scanNpmDependencies();
        $vulnerabilities = array_merge($vulnerabilities, $jsVulnerabilities);
        
        $this->newLine();
        
        // Display results
        if (empty($vulnerabilities)) {
            $this->info('✓ No known vulnerabilities found!');
            
            // Log successful scan
            DB::table('system_logs')->insert([
                'tenant_id' => null,
                'user_id' => null,
                'category' => 'security',
                'action' => 'dependency_scan_completed',
                'details' => json_encode([
                    'vulnerabilities_found' => 0,
                    'status' => 'clean',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            return 0;
        }
        
        // Display vulnerabilities
        $this->error("✗ Found " . count($vulnerabilities) . " vulnerabilities:");
        $this->newLine();
        
        $table = [];
        foreach ($vulnerabilities as $vuln) {
            $table[] = [
                $vuln['package'],
                $vuln['version'],
                $vuln['severity'],
                $vuln['title'],
            ];
        }
        
        $this->table(
            ['Package', 'Version', 'Severity', 'Vulnerability'],
            $table
        );
        
        // Log vulnerabilities found
        DB::table('system_logs')->insert([
            'tenant_id' => null,
            'user_id' => null,
            'category' => 'security',
            'action' => 'dependency_vulnerabilities_found',
            'details' => json_encode([
                'vulnerabilities_found' => count($vulnerabilities),
                'vulnerabilities' => $vulnerabilities,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        if ($this->option('report')) {
            $this->generateReport($vulnerabilities);
        }
        
        return 1;
    }
    
    /**
     * Scan Composer dependencies using composer audit
     */
    protected function scanComposerDependencies(): array
    {
        $vulnerabilities = [];
        $composerPath = base_path();
        
        if (!file_exists("{$composerPath}/composer.lock")) {
            $this->warn('composer.lock not found. Run composer install first.');
            return [];
        }
        
        // Run composer audit
        $output = [];
        $returnVar = 0;
        exec("cd {$composerPath} && composer audit --format=json 2>&1", $output, $returnVar);
        
        $jsonOutput = implode("\n", $output);
        $data = json_decode($jsonOutput, true);
        
        if ($data && isset($data['advisories'])) {
            foreach ($data['advisories'] as $package => $advisories) {
                foreach ($advisories as $advisory) {
                    $vulnerabilities[] = [
                        'type' => 'php',
                        'package' => $package,
                        'version' => $advisory['affectedVersions'] ?? 'unknown',
                        'severity' => $advisory['severity'] ?? 'unknown',
                        'title' => $advisory['title'] ?? 'Unknown vulnerability',
                        'cve' => $advisory['cve'] ?? null,
                    ];
                }
            }
        }
        
        return $vulnerabilities;
    }
    
    /**
     * Scan NPM dependencies using npm audit
     */
    protected function scanNpmDependencies(): array
    {
        $vulnerabilities = [];
        $frontendPath = base_path('../frontend');
        
        if (!file_exists("{$frontendPath}/package.json")) {
            $this->warn('package.json not found in frontend directory.');
            return [];
        }
        
        // Run npm audit
        $output = [];
        $returnVar = 0;
        exec("cd {$frontendPath} && npm audit --json 2>&1", $output, $returnVar);
        
        $jsonOutput = implode("\n", $output);
        $data = json_decode($jsonOutput, true);
        
        if ($data && isset($data['vulnerabilities'])) {
            foreach ($data['vulnerabilities'] as $package => $details) {
                if (isset($details['via']) && is_array($details['via'])) {
                    foreach ($details['via'] as $vuln) {
                        if (is_array($vuln)) {
                            $vulnerabilities[] = [
                                'type' => 'javascript',
                                'package' => $package,
                                'version' => $details['range'] ?? 'unknown',
                                'severity' => $vuln['severity'] ?? 'unknown',
                                'title' => $vuln['title'] ?? 'Unknown vulnerability',
                                'cve' => $vuln['cve'] ?? null,
                            ];
                        }
                    }
                }
            }
        }
        
        return $vulnerabilities;
    }
    
    /**
     * Generate detailed vulnerability report
     */
    protected function generateReport(array $vulnerabilities): void
    {
        $reportPath = storage_path('app/reports');
        
        if (!file_exists($reportPath)) {
            mkdir($reportPath, 0755, true);
        }
        
        $filename = 'vulnerability-scan-' . now()->format('Y-m-d_His') . '.json';
        $filepath = "{$reportPath}/{$filename}";
        
        $report = [
            'scan_date' => now()->toIso8601String(),
            'total_vulnerabilities' => count($vulnerabilities),
            'vulnerabilities' => $vulnerabilities,
            'severity_breakdown' => $this->getSeverityBreakdown($vulnerabilities),
        ];
        
        file_put_contents($filepath, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->newLine();
        $this->info("Detailed report saved to: {$filepath}");
    }
    
    /**
     * Get severity breakdown
     */
    protected function getSeverityBreakdown(array $vulnerabilities): array
    {
        $breakdown = [
            'critical' => 0,
            'high' => 0,
            'moderate' => 0,
            'low' => 0,
        ];
        
        foreach ($vulnerabilities as $vuln) {
            $severity = strtolower($vuln['severity']);
            if (isset($breakdown[$severity])) {
                $breakdown[$severity]++;
            }
        }
        
        return $breakdown;
    }
}
