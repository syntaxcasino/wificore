<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMailerSendCommand extends Command
{
    protected $signature = 'mail:test {email}';
    protected $description = 'Test MailerSend email sending';

    public function handle()
    {
        $email = $this->argument('email');
        
        $this->info('Sending test email to: ' . $email);
        
        try {
            Mail::raw('This is a test email from WifiCore using MailerSend!', function ($message) use ($email) {
                $message->to($email)
                    ->subject('Test Email from WifiCore');
            });
            
            $this->info('✅ Email sent successfully!');
            $this->info('Check your inbox at: ' . $email);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Failed to send email: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            
            return Command::FAILURE;
        }
    }
}
