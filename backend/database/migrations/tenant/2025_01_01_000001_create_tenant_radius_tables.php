<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: RADIUS tables for tenant-specific authentication
     * Each tenant has their own RADIUS tables for FreeRADIUS AAA
     */
    public function up(): void
    {
        // NAS (Network Access Server) Table
        Schema::create('nas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nasname', 128)->unique();
            $table->string('shortname', 32)->nullable();
            $table->string('type', 30)->default('other');
            $table->integer('ports')->nullable();
            $table->string('secret', 60)->default('secret');
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->default('RADIUS Client');
            
            $table->index('nasname');
        });

        // RADIUS Check Table (Authentication)
        Schema::create('radcheck', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->char('op', 2)->default('==');
            $table->string('value', 253)->default('');
            $table->timestamps();
            
            $table->index(['username', 'attribute']);
        });

        // RADIUS Reply Table (Authorization)
        Schema::create('radreply', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->char('op', 2)->default('=');
            $table->string('value', 253)->default('');
            $table->timestamps();
            
            $table->index(['username', 'attribute']);
        });

        // RADIUS User Group Table
        Schema::create('radusergroup', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 64)->default('');
            $table->string('groupname', 64)->default('');
            $table->integer('priority')->default(1);
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['username', 'priority']);
        });

        // RADIUS Group Check Table
        Schema::create('radgroupcheck', function (Blueprint $table) {
            $table->increments('id');
            $table->string('groupname', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->char('op', 2)->default('==');
            $table->string('value', 253)->default('');
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['groupname', 'attribute']);
        });

        // RADIUS Group Reply Table
        Schema::create('radgroupreply', function (Blueprint $table) {
            $table->increments('id');
            $table->string('groupname', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->char('op', 2)->default('=');
            $table->string('value', 253)->default('');
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['groupname', 'attribute']);
        });

        // RADIUS Accounting Table
        Schema::create('radacct', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('acctsessionid', 64)->default('');
            $table->string('acctuniqueid', 32)->default('')->unique();
            $table->string('username', 64)->default('');
            $table->string('groupname', 64)->default('');
            $table->string('realm', 64)->nullable();
            $table->ipAddress('nasipaddress');
            $table->string('nasportid', 15)->nullable();
            $table->string('nasporttype', 32)->nullable();
            $table->timestampTz('acctstarttime')->nullable();
            $table->timestampTz('acctupdatetime')->nullable();
            $table->timestampTz('acctstoptime')->nullable();
            $table->integer('acctinterval')->nullable();
            $table->integer('acctsessiontime')->nullable();
            $table->string('acctauthentic', 32)->nullable();
            $table->string('connectinfo_start', 50)->nullable();
            $table->string('connectinfo_stop', 50)->nullable();
            $table->bigInteger('acctinputoctets')->nullable();
            $table->bigInteger('acctoutputoctets')->nullable();
            $table->string('calledstationid', 50)->default('');
            $table->string('callingstationid', 50)->default('');
            $table->string('acctterminatecause', 32)->default('');
            $table->string('servicetype', 32)->nullable();
            $table->string('framedprotocol', 32)->nullable();
            $table->ipAddress('framedipaddress')->nullable();
            
            // Indexes for performance
            $table->index('username');
            $table->index('acctsessionid');
            $table->index('acctstarttime');
            $table->index('acctstoptime');
            $table->index('nasipaddress');
            $table->index(['username', 'acctstarttime']);
        });

        // RADIUS Post-Auth Table (Logging)
        Schema::create('radpostauth', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 64)->default('');
            $table->string('pass', 64)->default('');
            $table->string('reply', 32)->default('');
            $table->timestampTz('authdate')->useCurrent();
            
            $table->index('username');
            $table->index('authdate');
        });
        
        // Create default RADIUS groups for hotspot users
        DB::table('radgroupcheck')->insert([
            [
                'groupname' => 'hotspot_users',
                'attribute' => 'Auth-Type',
                'op' => ':=',
                'value' => 'Accept',
                'created_at' => now(),
            ],
        ]);
        
        DB::table('radgroupreply')->insert([
            [
                'groupname' => 'hotspot_users',
                'attribute' => 'Service-Type',
                'op' => ':=',
                'value' => 'Framed-User',
                'created_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('radpostauth');
        Schema::dropIfExists('radacct');
        Schema::dropIfExists('radgroupreply');
        Schema::dropIfExists('radgroupcheck');
        Schema::dropIfExists('radusergroup');
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('radcheck');
        Schema::dropIfExists('nas');
    }
};
