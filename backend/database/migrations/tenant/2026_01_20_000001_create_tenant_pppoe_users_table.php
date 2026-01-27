<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasTableInCurrentSchema = function ($tableName) {
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables
                    WHERE table_schema = CURRENT_SCHEMA()
                    AND table_name = ?
                ) as exists
            ", [$tableName]);

            return (bool) ($result->exists ?? false);
        };

        if ($hasTableInCurrentSchema('pppoe_users')) {
            return;
        }

        Schema::create('pppoe_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('username')->unique();
            $table->string('password');

            $table->uuid('package_id');
            $table->uuid('router_id');

            $table->timestamp('expires_at')->nullable();
            $table->string('rate_limit', 50)->nullable();
            $table->unsignedInteger('simultaneous_use')->default(1);

            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('package_id')
                ->references('id')
                ->on(new \Illuminate\Database\Query\Expression('public.packages'))
                ->onDelete('restrict');

            $table->foreign('router_id')
                ->references('id')
                ->on('routers')
                ->onDelete('cascade');

            $table->index('router_id');
            $table->index('package_id');
            $table->index('status');
            $table->index('is_active');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_users');
    }
};
