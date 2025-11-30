<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\HotspotUser;
use App\Models\HotspotSession;
use Carbon\Carbon;

class HotspotController extends Controller
{
    /**
     * Hotspot user login
     * 
     * This endpoint authenticates hotspot users who have purchased packages
     * and creates an active session for internet access.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
            'mac_address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $username = $request->input('username');
            $password = $request->input('password');
            $macAddress = $request->input('mac_address');

            // Find hotspot user by username
            $hotspotUser = HotspotUser::where('username', $username)->first();

            if (!$hotspotUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid username or password',
                ], 401);
            }

            // Verify password
            if (!password_verify($password, $hotspotUser->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid username or password',
                ], 401);
            }

            // Check if user has an active subscription
            if (!$hotspotUser->has_active_subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription. Please purchase a package first.',
                ], 403);
            }

            // Check if subscription is expired
            if ($hotspotUser->subscription_expires_at && 
                Carbon::parse($hotspotUser->subscription_expires_at)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your subscription has expired. Please renew your package.',
                ], 403);
            }

            // Create or update session
            $session = HotspotSession::updateOrCreate(
                ['hotspot_user_id' => $hotspotUser->id],
                [
                    'mac_address' => $macAddress,
                    'ip_address' => $request->ip(),
                    'session_start' => now(),
                    'last_activity' => now(),
                    'is_active' => true,
                    'expires_at' => $hotspotUser->subscription_expires_at,
                ]
            );

            // Update last login
            $hotspotUser->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            // Log successful login
            Log::info('Hotspot user logged in', [
                'user_id' => $hotspotUser->id,
                'username' => $username,
                'mac_address' => $macAddress,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login successful. You are now connected to the internet.',
                'data' => [
                    'user' => [
                        'id' => $hotspotUser->id,
                        'username' => $hotspotUser->username,
                        'phone_number' => $hotspotUser->phone_number,
                    ],
                    'session' => [
                        'id' => $session->id,
                        'session_start' => $session->session_start,
                        'expires_at' => $session->expires_at,
                    ],
                    'subscription' => [
                        'package_name' => $hotspotUser->package_name ?? 'N/A',
                        'expires_at' => $hotspotUser->subscription_expires_at,
                        'data_limit' => $hotspotUser->data_limit,
                        'data_used' => $hotspotUser->data_used,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Hotspot login error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during login. Please try again.',
            ], 500);
        }
    }

    /**
     * Hotspot user logout
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        try {
            $username = $request->input('username');
            $macAddress = $request->input('mac_address');

            // Find user
            $hotspotUser = HotspotUser::where('username', $username)->first();

            if (!$hotspotUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // End session
            $session = HotspotSession::where('hotspot_user_id', $hotspotUser->id)
                ->where('is_active', true)
                ->first();

            if ($session) {
                $session->update([
                    'is_active' => false,
                    'session_end' => now(),
                ]);
            }

            Log::info('Hotspot user logged out', [
                'user_id' => $hotspotUser->id,
                'username' => $username,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Hotspot logout error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during logout',
            ], 500);
        }
    }

    /**
     * Check session status
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkSession(Request $request)
    {
        try {
            $username = $request->input('username');
            $macAddress = $request->input('mac_address');

            $hotspotUser = HotspotUser::where('username', $username)->first();

            if (!$hotspotUser) {
                return response()->json([
                    'success' => false,
                    'is_active' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $session = HotspotSession::where('hotspot_user_id', $hotspotUser->id)
                ->where('is_active', true)
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => true,
                    'is_active' => false,
                    'message' => 'No active session',
                ], 200);
            }

            // Check if session expired
            if ($session->expires_at && Carbon::parse($session->expires_at)->isPast()) {
                $session->update(['is_active' => false, 'session_end' => now()]);
                
                return response()->json([
                    'success' => true,
                    'is_active' => false,
                    'message' => 'Session expired',
                ], 200);
            }

            // Update last activity
            $session->update(['last_activity' => now()]);

            return response()->json([
                'success' => true,
                'is_active' => true,
                'session' => [
                    'session_start' => $session->session_start,
                    'expires_at' => $session->expires_at,
                    'last_activity' => $session->last_activity,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Check session error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
            ], 500);
        }
    }
}
