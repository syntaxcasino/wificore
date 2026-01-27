<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_snmp_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id')->index();
            $table->json('payload');
            $table->timestamp('collected_at')->index();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_snmp_snapshots');
    }
};
