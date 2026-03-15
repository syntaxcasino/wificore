<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wireguard_peers')) {
            return;
        }

        $hasTransferRx = Schema::hasColumn('wireguard_peers', 'transfer_rx');
        $hasTransferTx = Schema::hasColumn('wireguard_peers', 'transfer_tx');

        if ($hasTransferRx && $hasTransferTx) {
            return;
        }

        Schema::table('wireguard_peers', function (Blueprint $table) use ($hasTransferRx, $hasTransferTx) {
            if (!$hasTransferRx) {
                $table->unsignedBigInteger('transfer_rx')->default(0)->after('allowed_ips');
            }

            if (!$hasTransferTx) {
                $table->unsignedBigInteger('transfer_tx')->default(0)->after($hasTransferRx ? 'transfer_rx' : 'allowed_ips');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('wireguard_peers')) {
            return;
        }

        $hasTransferRx = Schema::hasColumn('wireguard_peers', 'transfer_rx');
        $hasTransferTx = Schema::hasColumn('wireguard_peers', 'transfer_tx');

        if (!$hasTransferRx && !$hasTransferTx) {
            return;
        }

        Schema::table('wireguard_peers', function (Blueprint $table) use ($hasTransferRx, $hasTransferTx) {
            if ($hasTransferTx) {
                $table->dropColumn('transfer_tx');
            }

            if ($hasTransferRx) {
                $table->dropColumn('transfer_rx');
            }
        });
    }
};
