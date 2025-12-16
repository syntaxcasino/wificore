<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\CreateTenantJob;
use App\Notifications\TenantCredentialsEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    /**
     * Verify tenant email address
     */
    public function verify(Request $request, $id, $hash)
    {
        $tenant = Tenant::findOrFail($id);

        // Verify hash matches
        if (!hash_equals($hash, sha1($tenant->email))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification link.',
            ], 400);
        }

        // Check if already verified
        if ($tenant->email_verified_at) {
            return response()->json([
                'success' => true,
                'message' => 'Email already verified.',
                'data' => [
                    'status' => 'already_verified',
                ],
            ], 200);
        }

        try {
            DB::beginTransaction();

            // Mark email as verified
            $tenant->email_verified_at = now();
            $tenant->save();

            // Get pending credentials from settings
            $username = $tenant->settings['pending_username'] ?? null;
            $password = $tenant->settings['pending_password'] ?? null;

            if (!$username || !$password) {
                throw new \Exception('Pending credentials not found');
            }

            // Now create the tenant schema and admin user asynchronously
            $tenantData = [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'address' => $tenant->address,
            ];

            $adminData = [
                'name' => $tenant->name . ' Admin',
                'username' => $username,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
            ];

            // Dispatch job to create schema and user
            CreateTenantJob::dispatch($tenantData, $adminData, $password)
                ->onQueue('tenant-management');

            // Store credentials for sending after job completes
            $tenant->update([
                'settings' => array_merge($tenant->settings, [
                    'credentials_sent' => false,
                    'verification_completed_at' => now()->toIso8601String(),
                ])
            ]);

            DB::commit();

            Log::info('Email verified - tenant creation job dispatched', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully! Your account is being set up.',
                'data' => [
                    'tenant_id' => $tenant->id,
                    'status' => 'verified',
                    'step' => 'creating_account',
                    'subdomain' => $tenant->slug . '.wificore.traidsolutions.com',
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Email verification failed', [
                'tenant_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Verification failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Check verification status
     */
    public function checkStatus(Request $request, $tenantId)
    {
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found.',
            ], 404);
        }

        $status = 'pending_verification';
        $step = 1;

        if ($tenant->email_verified_at) {
            $status = 'verified';
            $step = 2;

            // Check if schema and user created
            if ($tenant->schema_created) {
                $status = 'account_created';
                $step = 3;

                // Check if credentials sent
                if ($tenant->settings['credentials_sent'] ?? false) {
                    $status = 'completed';
                    $step = 4;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $status,
                'step' => $step,
                'email_verified' => (bool) $tenant->email_verified_at,
                'schema_created' => (bool) $tenant->schema_created,
                'credentials_sent' => (bool) ($tenant->settings['credentials_sent'] ?? false),
                'subdomain' => $tenant->slug . '.wificore.traidsolutions.com',
            ],
        ], 200);
    }
}
