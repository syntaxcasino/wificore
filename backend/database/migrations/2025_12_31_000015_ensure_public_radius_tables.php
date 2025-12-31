<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure RADIUS tables exist in the public schema for Landlord usage
        
        if (!Schema::hasTable('nas')) {
            Schema::create('nas', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('nasname', 128)->unique();
                $table->string('shortname', 32)->nullable();
                $table->string('type', 32)->default('other');
                $table->integer('ports')->nullable();
                $table->string('secret', 60);
                $table->string('server', 64)->nullable();
                $table->string('community', 50)->nullable();
                $table->string('description', 128)->nullable();

                $table->index('nasname', 'nas_nasname_idx');
            });
        }

        if (!Schema::hasTable('radcheck')) {
            Schema::create('radcheck', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('username', 64)->default('');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('==');
                $table->string('value', 253)->default('');

                $table->index(['username', 'attribute'], 'radcheck_username_attribute_idx');
            });
        }

        if (!Schema::hasTable('radreply')) {
            Schema::create('radreply', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('username', 64)->default('');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('=');
                $table->string('value', 253)->default('');

                $table->index(['username', 'attribute'], 'radreply_username_attribute_idx');
            });
        }

        if (!Schema::hasTable('radusergroup')) {
            Schema::create('radusergroup', function (Blueprint $table) {
                $table->increments('id');
                $table->string('username', 64)->default('');
                $table->string('groupname', 64)->default('');
                $table->integer('priority')->default(1);
                $table->timestamp('created_at')->useCurrent();
                
                $table->index(['username', 'priority']);
            });
        }

        if (!Schema::hasTable('radgroupcheck')) {
            Schema::create('radgroupcheck', function (Blueprint $table) {
                $table->increments('id');
                $table->string('groupname', 64)->default('');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('==');
                $table->string('value', 253)->default('');
                $table->timestamp('created_at')->useCurrent();
                
                $table->index(['groupname', 'attribute']);
            });
        }

        if (!Schema::hasTable('radgroupreply')) {
            Schema::create('radgroupreply', function (Blueprint $table) {
                $table->increments('id');
                $table->string('groupname', 64)->default('');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('=');
                $table->string('value', 253)->default('');
                $table->timestamp('created_at')->useCurrent();
                
                $table->index(['groupname', 'attribute']);
            });
        }

        if (!Schema::hasTable('radacct')) {
            Schema::create('radacct', function (Blueprint $table) {
                $table->bigIncrements('radacctid');
                $table->string('acctsessionid', 64);
                $table->string('acctuniqueid', 32)->unique();
                $table->string('username', 64)->nullable();
                $table->string('realm', 64)->nullable();
                $table->ipAddress('nasipaddress');
                $table->string('nasportid', 15)->nullable();
                $table->timestampTz('acctstarttime')->nullable();
                $table->timestampTz('acctupdatetime')->nullable();
                $table->timestampTz('acctstoptime')->nullable();
                $table->bigInteger('acctinterval')->nullable();
                $table->bigInteger('acctsessiontime')->nullable();
                $table->string('acctauthentic', 32)->nullable();
                $table->string('connectinfo_start', 50)->nullable();
                $table->string('connectinfo_stop', 50)->nullable();
                $table->bigInteger('acctinputoctets')->default(0);
                $table->bigInteger('acctoutputoctets')->default(0);
                $table->string('calledstationid', 50)->nullable();
                $table->string('callingstationid', 50)->nullable();
                $table->string('acctterminatecause', 32)->nullable();
                $table->string('servicetype', 32)->nullable();
                $table->string('framedprotocol', 32)->nullable();
                $table->ipAddress('framedipaddress')->nullable();
                $table->bigInteger('acctstartdelay')->default(0);
                $table->bigInteger('acctstopdelay')->default(0);
                
                $table->index('username');
                $table->index('acctsessionid');
                $table->index('acctuniqueid');
                $table->index('acctstarttime');
                $table->index('acctstoptime');
                $table->index('nasipaddress');
            });
        }

        if (!Schema::hasTable('radpostauth')) {
            Schema::create('radpostauth', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('username', 64);
                $table->string('pass', 64)->nullable();
                $table->string('reply', 32)->nullable();
                $table->timestampTz('authdate')->useCurrent();

                $table->index('username', 'radpostauth_username_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop these as they might contain important landlord data
    }
};
