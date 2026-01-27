<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->boolean('snmp_enabled')->default(true)->after('reserved_interfaces');
            $table->string('snmp_version', 10)->default('v3')->after('snmp_enabled');

            $table->string('snmp_v3_user', 64)->nullable()->after('snmp_version');
            $table->string('snmp_v3_auth_protocol', 16)->default('SHA1')->after('snmp_v3_user');
            $table->text('snmp_v3_auth_password')->nullable()->after('snmp_v3_auth_protocol');
            $table->string('snmp_v3_priv_protocol', 16)->default('AES')->after('snmp_v3_auth_password');
            $table->text('snmp_v3_priv_password')->nullable()->after('snmp_v3_priv_protocol');

            $table->boolean('snmp_trap_enabled')->default(true)->after('snmp_v3_priv_password');
            $table->string('snmp_trap_version', 10)->default('v3')->after('snmp_trap_enabled');
            $table->text('snmp_trap_community')->nullable()->after('snmp_trap_version');
            $table->string('snmp_trap_target', 64)->nullable()->after('snmp_trap_community');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn([
                'snmp_enabled',
                'snmp_version',
                'snmp_v3_user',
                'snmp_v3_auth_protocol',
                'snmp_v3_auth_password',
                'snmp_v3_priv_protocol',
                'snmp_v3_priv_password',
                'snmp_trap_enabled',
                'snmp_trap_version',
                'snmp_trap_community',
                'snmp_trap_target',
            ]);
        });
    }
};
