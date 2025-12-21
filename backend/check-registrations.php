<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Recent Tenant Registrations ===\n\n";

$registrations = DB::table('tenant_registrations')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

if ($registrations->isEmpty()) {
    echo "No registrations found.\n";
} else {
    foreach ($registrations as $reg) {
        echo "ID: {$reg->id}\n";
        echo "Token: {$reg->token}\n";
        echo "Email: {$reg->tenant_email}\n";
        echo "Status: {$reg->status}\n";
        echo "Email Verified: " . ($reg->email_verified ? 'Yes' : 'No') . "\n";
        echo "Created: {$reg->created_at}\n";
        echo "---\n";
    }
}

echo "\n=== Checking specific token ===\n";
$token = '343b53c5679fce508bb357bf1bf9350c0f9c1ea2e449cd45991c58b8f2b26c10';
$reg = DB::table('tenant_registrations')->where('token', $token)->first();

if ($reg) {
    echo "Found registration with token!\n";
    print_r($reg);
} else {
    echo "No registration found with token: {$token}\n";
}
