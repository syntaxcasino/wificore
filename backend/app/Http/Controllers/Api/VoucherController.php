<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\Package;
use App\Events\VoucherCreated;
use App\Events\VoucherUpdated;
use App\Events\VoucherDeleted;
use App\Helpers\PackageExpiryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    /**
     * List all vouchers with optional filters.
     */
    public function index(Request $request)
    {
        // Optimized query with specific column selection
        $query = Voucher::select([
            'id', 'code', 'package_id', 'router_id', 'status', 'used_by',
            'used_at', 'expires_at', 'prefix', 'notes', 'batch_id',
            'created_at', 'updated_at'
        ])
        ->with([
            'package:id,name,price,download_speed,validity'
        ])
        ->orderBy('created_at', 'desc');

        // Apply filters efficiently
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('package_id')) {
            $query->where('package_id', $request->package_id);
        }

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('code', 'ilike', $search . '%'); // More efficient prefix search
        }

        // Optimized pagination with reasonable limits
        $perPage = min($request->input('per_page', 25), 100); // Max 100 per page
        $vouchers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $vouchers,
        ]);
    }

    /**
     * Show a single voucher.
     */
    public function show(Voucher $voucher)
    {
        // Optimized query with specific column selection
        $voucher->load([
            'package:id,name,price,download_speed,validity', 
            'router:id,name', 
            'usedBy:id,name,email'
        ]);

        return response()->json([
            'success' => true,
            'data' => $voucher,
        ]);
    }

    /**
     * Generate vouchers in batch.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'quantity' => 'required|integer|min:1|max:100',
            'prefix' => 'nullable|string|max:10',
            'expires_at' => 'nullable|date|after:today',
            'router_id' => 'nullable|exists:routers,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $tenantId = $request->user()->tenant_id;
        $batchId = Str::uuid()->toString();
        $prefix = isset($validated['prefix']) ? strtoupper($validated['prefix']) : null;
        $generatedCodes = [];
        $timestamp = now();

        // Fetch package details for value and duration
        $package = Package::select(['id', 'price', 'validity'])->find($validated['package_id']);

        // Optimized batch insert for better performance
        $voucherData = [];
        for ($i = 0; $i < $validated['quantity']; $i++) {
            $code = $this->generateUniqueCode($prefix ?? '', $generatedCodes);
            $generatedCodes[$code] = true;

            $voucherData[] = [
                'id' => Str::uuid()->toString(),
                'code' => $code,
                'package_id' => $validated['package_id'],
                'value' => $package?->price,
                'package_duration_days' => $package ? PackageExpiryHelper::durationInDays($package) : null,
                'router_id' => $validated['router_id'] ?? null,
                'status' => 'unused',
                'expires_at' => $validated['expires_at'] ?? null,
                'prefix' => $prefix,
                'notes' => $validated['notes'] ?? null,
                'batch_id' => $batchId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        // Batch insert for performance
        Voucher::insert($voucherData);

        // Retrieve inserted vouchers with relationships
        $insertedVouchers = Voucher::with([
            'package:id,name,price,download_speed,validity', 
            'router:id,name'
        ])
        ->where('batch_id', $batchId)
        ->orderBy('created_at', 'desc')
        ->get();

        // Broadcast events for each voucher created
        foreach ($insertedVouchers as $voucher) {
            broadcast(new VoucherCreated($voucher, $tenantId))->toOthers();
        }

        // Clear cache for current tenant - comprehensive cache busting
        $this->bustVoucherCache((string) $tenantId);

        return response()->json([
            'success' => true,
            'message' => "Successfully generated {$validated['quantity']} voucher(s).",
            'data' => [
                'batch_id' => $batchId,
                'count' => $insertedVouchers->count(),
                'vouchers' => $insertedVouchers,
            ],
        ], 201);
    }

    /**
     * Revoke a voucher (mark as revoked).
     */
    public function revoke(Voucher $voucher)
    {
        if ($voucher->status === 'used') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke a voucher that has already been used.',
            ], 422);
        }

        $voucher->update(['status' => 'revoked']);

        // Clear cache for current tenant - comprehensive cache busting
        $tenantId = auth()->user()?->tenant_id;
        $this->bustVoucherCache((string) $tenantId);

        // Broadcast event
        broadcast(new VoucherUpdated($voucher, $tenantId))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Voucher revoked successfully.',
            'data' => $voucher->fresh(['package', 'router']),
        ]);
    }

    /**
     * Delete a voucher (soft delete).
     */
    public function destroy(Voucher $voucher)
    {
        if ($voucher->status === 'used') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a voucher that has been used.',
            ], 422);
        }

        $voucherId = $voucher->id;
        $voucherCode = $voucher->code;
        $tenantId = auth()->user()?->tenant_id;

        $voucher->delete();

        // Clear cache for current tenant - comprehensive cache busting
        $this->bustVoucherCache((string) $tenantId);

        // Broadcast event
        broadcast(new VoucherDeleted($voucherId, $tenantId, $voucherCode))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Voucher deleted successfully.',
        ]);
    }

    /**
     * Get voucher statistics.
     */
    public function stats()
    {
        $tenantId = auth()->user()?->tenant_id;
        $cacheKey = 'voucher_stats_tenant_' . $tenantId;

        $stats = Cache::remember($cacheKey, now()->addSeconds(15), function () {
            return [
                'total' => Voucher::count(),
                'unused' => Voucher::where('status', 'unused')->count(),
                'used' => Voucher::where('status', 'used')->count(),
                'expired' => Voucher::where('status', 'expired')->count(),
                'revoked' => Voucher::where('status', 'revoked')->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get recent batch generations.
     */
    public function recentBatches(Request $request)
    {
        $tenantId = auth()->user()?->tenant_id;
        $limit = max(1, min((int) $request->input('limit', 10), 25));
        $cacheKey = 'voucher_recent_batches_tenant_' . $tenantId . '_limit_' . $limit;

        $result = Cache::remember($cacheKey, now()->addSeconds(15), function () use ($limit) {
            $batches = Voucher::selectRaw("
                    batch_id,
                    COUNT(*) as quantity,
                    MIN(created_at) as created_at,
                    MAX(package_id) as package_id
                ")
                ->whereNotNull('batch_id')
                ->groupBy('batch_id')
                ->orderByRaw('MIN(created_at) DESC')
                ->limit($limit)
                ->get();

            // Load package names
            $packageIds = $batches->pluck('package_id')->unique();
            $packages = Package::whereIn('id', $packageIds)->pluck('name', 'id');

            return $batches->map(function ($batch) use ($packages) {
                $statusCounts = Voucher::where('batch_id', $batch->batch_id)
                    ->selectRaw("status, COUNT(*) as count")
                    ->groupBy('status')
                    ->pluck('count', 'status');

                return [
                    'batch_id' => $batch->batch_id,
                    'quantity' => $batch->quantity,
                    'package' => $packages[$batch->package_id] ?? 'Unknown',
                    'package_id' => $batch->package_id,
                    'created_at' => $batch->created_at,
                    'status_counts' => $statusCounts,
                ];
            })->values();
        });

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Generate a unique voucher code.
     */
    private function generateUniqueCode(string $prefix = '', array $generatedCodes = []): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxAttempts = 10;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = $prefix ? strtoupper($prefix) . '-' : '';
            $segments = [];
            for ($s = 0; $s < 3; $s++) {
                $segment = '';
                for ($c = 0; $c < 4; $c++) {
                    $segment .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $segments[] = $segment;
            }
            $code .= implode('-', $segments);

            if (!isset($generatedCodes[$code]) && !Voucher::where('code', $code)->exists()) {
                return $code;
            }
        }

        // Fallback: append UUID fragment
        return ($prefix ? strtoupper($prefix) . '-' : '') . strtoupper(Str::random(12));
    }

    /**
     * Comprehensive cache busting for vouchers to prevent stale data
     */
    private function bustVoucherCache(string $tenantId): void
    {
        Cache::forget("vouchers_list_tenant_{$tenantId}");
        Cache::forget("voucher_stats_tenant_{$tenantId}");

        TenantDashboardController::bustDashboardCache($tenantId);
        TenantDashboardController::bustEntityCache($tenantId, 'packages');

        $vouchers = Voucher::select('batch_id')->distinct()->whereNotNull('batch_id')->get();
        foreach ($vouchers as $voucher) {
            Cache::forget("voucher_batch_{$voucher->batch_id}_tenant_{$tenantId}");
        }

        for ($limit = 1; $limit <= 25; $limit++) {
            Cache::forget("voucher_recent_batches_tenant_{$tenantId}_limit_{$limit}");
        }

        Cache::tags(["voucher_search_{$tenantId}"])->flush();
        Cache::tags(["voucher_filters_{$tenantId}"])->flush();
    }
}
