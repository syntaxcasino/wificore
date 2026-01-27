<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add SSH key support for enhanced security.
     * SSH keys are preferred over passwords for router authentication.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->text('ssh_key')->nullable()->after('password')
                ->comment('Encrypted SSH private key for key-based authentication (preferred)');
            $table->timestamp('ssh_key_created_at')->nullable()->after('ssh_key')
                ->comment('When the SSH key was first created');
            $table->timestamp('ssh_key_rotated_at')->nullable()->after('ssh_key_created_at')
                ->comment('When the SSH key was last rotated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['ssh_key', 'ssh_key_created_at', 'ssh_key_rotated_at']);
        });
    }
};
