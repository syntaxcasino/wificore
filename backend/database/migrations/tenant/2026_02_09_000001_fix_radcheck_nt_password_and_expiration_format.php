<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fix existing radcheck data for PPPoE users:
 * 1. NT-Password: convert lowercase hex to uppercase hex (FreeRADIUS mschap requires uppercase)
 * 2. Expiration: convert Unix timestamps to FreeRADIUS date format ("F d Y H:i:s")
 * 
 * Also regenerates NT-Password from Cleartext-Password for users where the hash
 * may have been computed incorrectly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('radcheck')) {
            return;
        }

        // Fix 1: Convert NT-Password values to uppercase hex
        // FreeRADIUS mschap module requires uppercase hex for NT-Password
        $ntPasswords = DB::table('radcheck')
            ->where('attribute', 'NT-Password')
            ->get();

        foreach ($ntPasswords as $row) {
            $upper = strtoupper($row->value);
            if ($upper !== $row->value) {
                DB::table('radcheck')
                    ->where('id', $row->id)
                    ->update(['value' => $upper, 'updated_at' => now()]);
            }
        }

        Log::info('Migration: Fixed NT-Password case for ' . $ntPasswords->count() . ' rows');

        // Fix 2: Regenerate NT-Password from Cleartext-Password for all users
        // This ensures the hash is correct even if the original computation was wrong
        $cleartextPasswords = DB::table('radcheck')
            ->where('attribute', 'Cleartext-Password')
            ->get();

        foreach ($cleartextPasswords as $row) {
            $plainPassword = $row->value;
            if (empty($plainPassword)) {
                continue;
            }

            // Compute correct NT-Password hash (MD4 of UTF-16LE encoded password, uppercase hex)
            if (function_exists('mb_convert_encoding')) {
                $utf16le = mb_convert_encoding($plainPassword, 'UTF-16LE', 'UTF-8');
            } else {
                $utf16le = iconv('UTF-8', 'UTF-16LE', $plainPassword);
            }
            $ntHash = strtoupper(hash('md4', $utf16le));

            DB::table('radcheck')->updateOrInsert(
                ['username' => $row->username, 'attribute' => 'NT-Password'],
                ['op' => ':=', 'value' => $ntHash, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Log::info('Migration: Regenerated NT-Password for ' . $cleartextPasswords->count() . ' users');

        // Fix 3: Convert Expiration values from Unix timestamps to FreeRADIUS date format
        // FreeRADIUS expects: "February 09 2026 12:00:00" (PHP format: "F d Y H:i:s")
        $expirations = DB::table('radcheck')
            ->where('attribute', 'Expiration')
            ->get();

        $fixedCount = 0;
        foreach ($expirations as $row) {
            $val = trim($row->value);
            if (empty($val)) {
                continue;
            }

            // Check if value looks like a Unix timestamp (all digits, reasonable range)
            if (preg_match('/^\d{9,11}$/', $val)) {
                $ts = (int) $val;
                $formatted = date('F d Y H:i:s', $ts);

                DB::table('radcheck')
                    ->where('id', $row->id)
                    ->update(['value' => $formatted, 'updated_at' => now()]);

                $fixedCount++;
            }
        }

        Log::info('Migration: Fixed Expiration format for ' . $fixedCount . ' rows (total checked: ' . $expirations->count() . ')');
    }

    public function down(): void
    {
        // Not reversible — the old format was incorrect
    }
};
