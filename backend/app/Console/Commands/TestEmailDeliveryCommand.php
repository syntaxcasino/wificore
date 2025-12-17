<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class TestEmailDeliveryCommand extends Command
{
    protected $signature = 'email:test-delivery {email} {--quick : Skip detailed diagnostics}';
    protected $description = 'Test email delivery speed and configuration';

    public function handle()
    {
        $email = $this->argument('email');
        $quick = $this->option('quick');
        
        $this->info('🔍 Email Delivery Test');
        $this->newLine();
        
        // Show configuration
        if (!$quick) {
            $this->showConfiguration();
        }
        
        // Test email sending
        $this->testEmailSending($email);
        
        return Command::SUCCESS;
    }
    
    private function showConfiguration()
    {
        $this->info('📧 Current Mail Configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Mailer', config('mail.default')],
                ['Host', config('mail.mailers.smtp.host')],
                ['Port', config('mail.mailers.smtp.port')],
                ['Encryption', config('mail.mailers.smtp.encryption')],
                ['Username', config('mail.mailers.smtp.username')],
                ['Timeout', config('mail.mailers.smtp.timeout') . 's'],
                ['From Address', config('mail.from.address')],
                ['From Name', config('mail.from.name')],
            ]
        );
        $this->newLine();
    }
    
    private function testEmailSending($email)
    {
        $this->info("📤 Sending test email to: {$email}");
        
        $startTime = microtime(true);
        
        try {
            Mail::raw(
                "This is a test email from WifiCore.\n\nSent at: " . now()->toDateTimeString() . "\n\nIf you received this, email delivery is working!",
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('WifiCore Email Delivery Test - ' . now()->format('H:i:s'))
                        ->priority(1);
                }
            );
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->newLine();
            $this->info("✅ Email sent successfully!");
            $this->info("⏱️  Delivery time: {$duration}ms");
            
            if ($duration > 5000) {
                $this->warn("⚠️  Slow delivery detected (>{$duration}ms). Consider using a faster SMTP provider.");
            } elseif ($duration > 2000) {
                $this->comment("⚡ Moderate speed ({$duration}ms). Acceptable for production.");
            } else {
                $this->info("🚀 Fast delivery ({$duration}ms). Excellent!");
            }
            
            $this->newLine();
            $this->info("📬 Check your inbox at: {$email}");
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->newLine();
            $this->error("❌ Failed to send email!");
            $this->error("⏱️  Failed after: {$duration}ms");
            $this->error("🔥 Error: " . $e->getMessage());
            $this->newLine();
            
            $this->comment("💡 Troubleshooting tips:");
            $this->line("  1. Check SMTP credentials in .env file");
            $this->line("  2. Verify SMTP host and port are correct");
            $this->line("  3. Ensure firewall allows outbound SMTP connections");
            $this->line("  4. Check MAIL_ENCRYPTION is set to 'tls' or 'ssl'");
            $this->line("  5. Verify MAIL_MAILER is set to 'smtp' in .env");
            
            return Command::FAILURE;
        }
    }
}
