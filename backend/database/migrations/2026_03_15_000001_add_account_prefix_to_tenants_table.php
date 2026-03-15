<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'account_prefix')) {
                $table->string('account_prefix', 3)->nullable()->unique()->after('slug');
            }
        });

        // Back-fill existing tenants: derive a unique 3-char alphanumeric prefix
        // from their slug/name so existing account numbers remain consistent.
        $tenants = DB::table('tenants')->whereNull('account_prefix')->orderBy('created_at')->get(['id', 'slug', 'name']);

        $usedPrefixes = [];

        foreach ($tenants as $tenant) {
            $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $tenant->slug ?? $tenant->name));
            $base = substr($base, 0, 3);
            $base = str_pad($base, 3, '0');

            $candidate = $base;
            $suffix = 0;

            while (in_array($candidate, $usedPrefixes, true)) {
                $suffix++;
                $candidate = substr($base, 0, 2) . base_convert($suffix, 10, 36);
                $candidate = strtoupper(substr($candidate, 0, 3));
            }

            $usedPrefixes[] = $candidate;

            DB::table('tenants')->where('id', $tenant->id)->update(['account_prefix' => $candidate]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'account_prefix')) {
                $table->dropColumn('account_prefix');
            }
        });
    }
};
