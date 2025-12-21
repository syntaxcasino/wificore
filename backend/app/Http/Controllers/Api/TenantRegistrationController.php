<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Jobs\SendVerificationEmailJob;
use App\Jobs\CreateTenantWorkspaceJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TenantRegistrationController extends Controller
{
    /**
     * Register a new tenant - Step 1: Create registration and send verification email
     * Public endpoint - no authentication required
     */
    public function register(Request $request)
    {
        // Validate registration data
        $validator = Validator::make($request->all(), [
            'tenant_name' => 'required|string|min:3|max:255',
            'tenant_email' => 'nullable|email|max:255',
            'tenant_phone' => 'nullable|string|max:50',
            'tenant_address' => 'nullable|string|max:500',
            'accept_terms' => 'required|accepted',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Generate slug from tenant name
        $slug = $this->generateSlug($request->tenant_name);
        
        // Check if slug already exists
        if (Tenant::where('slug', $slug)->exists() || TenantRegistration::where('tenant_slug', $slug)->where('status', '!=', 'failed')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A tenant with a similar name already exists. Please choose a different company name.',
                'errors' => ['tenant_name' => ['This company name is already taken']]
            ], 422);
        }

        try {
            // Generate unique token first
            $token = TenantRegistration::generateToken();
            
            // Use tenant_email or generate a unique placeholder using token
            $email = $request->tenant_email ?: $slug . '-' . substr($token, 0, 8) . '@temp.wificore.local';

            // Create registration record
            $registration = TenantRegistration::create([
                'token' => $token,
                'tenant_name' => $request->tenant_name,
                'tenant_slug' => $slug,
                'tenant_email' => $email,
                'tenant_phone' => $request->tenant_phone,
                'tenant_address' => $request->tenant_address,
                'status' => 'pending',
            ]);

            // Dispatch verification email job
            SendVerificationEmailJob::dispatch($registration)
                ->onQueue('emails');

            Log::info('Tenant registration initiated', [
                'registration_id' => $registration->id,
                'tenant_slug' => $slug,
                'tenant_email' => $email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Registration initiated. Please check your email to verify your account.',
                'token' => $registration->token,
                'data' => [
                    'tenant_slug' => $slug,
                    'tenant_email' => $email,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Tenant registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed due to a system error. Please try again or contact support if the issue persists.',
                'error_code' => 'REGISTRATION_ERROR',
                'details' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Verify email - Step 2: Verify email and trigger workspace creation
     */
    public function verifyEmail($token)
    {
        $registration = TenantRegistration::where('token', $token)
            ->where('email_verified', false)
            ->first();

        if (!$registration) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification token.',
            ], 404);
        }

        try {
            // Mark email as verified
            $registration->update([
                'email_verified' => true,
                'email_verified_at' => now(),
                'status' => 'verified',
            ]);

            Log::info('Email verified', [
                'registration_id' => $registration->id,
                'tenant_slug' => $registration->tenant_slug,
            ]);

            // Dispatch workspace creation job
            CreateTenantWorkspaceJob::dispatch($registration)
                ->onQueue('tenant-management');

            // Return JSON response with redirect information
            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully. Your workspace is being created.',
                'data' => [
                    'tenant_slug' => $registration->tenant_slug,
                    'status' => 'verified',
                    'next_step' => 'workspace_creation'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Email verification failed', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Verification failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Get registration status - For polling
     */
    public function getStatus($token)
    {
        $registration = TenantRegistration::where('token', $token)->first();

        if (!$registration) {
            return response()->json([
                'success' => false,
                'message' => 'Registration not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => $registration->status,
            'email_verified' => $registration->email_verified,
            'credentials_sent' => $registration->credentials_sent,
            'tenant_slug' => $registration->tenant_slug,
            'error_message' => $registration->error_message,
        ]);
    }

    /**
     * Resend verification email
     */
    public function resendVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $registration = TenantRegistration::where('token', $request->token)
            ->where('email_verified', false)
            ->first();

        if (!$registration) {
            return response()->json([
                'success' => false,
                'message' => 'Registration not found or already verified.',
            ], 404);
        }

        try {
            // Dispatch verification email job
            SendVerificationEmailJob::dispatch($registration)
                ->onQueue('emails');

            Log::info('Verification email resent', [
                'registration_id' => $registration->id,
                'tenant_slug' => $registration->tenant_slug,
                'tenant_email' => $registration->tenant_email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Verification email has been resent. Please check your inbox.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to resend verification email', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resend verification email. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate slug from company name
     */
    private function generateSlug($name)
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

}
