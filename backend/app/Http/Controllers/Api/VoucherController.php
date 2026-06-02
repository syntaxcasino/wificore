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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    /**
     * List all vouchers with optional filters.
     */
    public function index(Request $request)
    {
        // Optimized query with specific column selection
        $query = Voucher::select($this->voucherListColumns())
        ->with([
            'package:id,name,price,download_speed,validity'
        ])
        ->orderBy('created_at', 'desc');

        // Exclude archived vouchers unless explicitly requested
        $this->applyArchivedFilter($query, $request->boolean('include_archived'));

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
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', $search . '%')
                  ->orWhere('status', 'ilike', $search)
                  ->orWhereHas('package', function ($pkg) use ($search) {
                      $pkg->where('name', 'ilike', $search . '%');
                  });
            });
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
        $package = Package::select(['id', 'price', 'validity', 'duration'])->find($validated['package_id']);

        // Auto-calculate expiry from package only if not explicitly provided in request.
        // If user clears the field (sends null/empty), leave as null so voucher never expires.
        $expiresAt = $validated['expires_at'] ?? null;
        if (!$expiresAt && $package && !$request->has('expires_at')) {
            $expiresAt = PackageExpiryHelper::calculateExpiresAt($package, now())->toDateTimeString();
        }

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
                'expires_at' => $expiresAt,
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
        if ($voucher->used_by) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke a voucher associated with a user.',
            ], 422);
        }

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
        if ($voucher->used_by) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a voucher associated with a user.',
            ], 422);
        }

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

        $supportsArchiving = $this->voucherSupportsArchiving();

        $stats = Cache::remember($cacheKey, now()->addSeconds(15), function () use ($supportsArchiving) {
            if (! $supportsArchiving) {
                return [
                    'total' => Voucher::count(),
                    'unused' => Voucher::where('status', 'unused')->count(),
                    'used' => Voucher::where('status', 'used')->count(),
                    'expired' => Voucher::where('status', 'expired')->count(),
                    'revoked' => Voucher::where('status', 'revoked')->count(),
                    'archived' => 0,
                ];
            }

            return [
                'total' => Voucher::whereNull('archived_at')->count(),
                'unused' => Voucher::where('status', 'unused')->whereNull('archived_at')->count(),
                'used' => Voucher::where('status', 'used')->whereNull('archived_at')->count(),
                'expired' => Voucher::where('status', 'expired')->whereNull('archived_at')->count(),
                'revoked' => Voucher::where('status', 'revoked')->whereNull('archived_at')->count(),
                'archived' => Voucher::whereNotNull('archived_at')->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Archive a voucher (soft-hide from main list).
     */
    public function archive(Voucher $voucher)
    {
        if (! $this->voucherSupportsArchiving()) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher archiving is not available until the archived_at column is deployed.',
            ], 409);
        }

        if ($voucher->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher is already archived.',
            ], 422);
        }

        $voucher->update(['archived_at' => now()]);

        $tenantId = auth()->user()?->tenant_id;
        $this->bustVoucherCache((string) $tenantId);
        broadcast(new VoucherUpdated($voucher->fresh(), $tenantId))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Voucher archived successfully.',
            'data' => $voucher->fresh(['package', 'router']),
        ]);
    }

    /**
     * Restore (unarchive) a voucher.
     */
    public function restore(Voucher $voucher)
    {
        if (! $this->voucherSupportsArchiving()) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher archiving is not available until the archived_at column is deployed.',
            ], 409);
        }

        if (!$voucher->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher is not archived.',
            ], 422);
        }

        $voucher->update(['archived_at' => null]);

        $tenantId = auth()->user()?->tenant_id;
        $this->bustVoucherCache((string) $tenantId);
        broadcast(new VoucherUpdated($voucher->fresh(), $tenantId))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Voucher restored successfully.',
            'data' => $voucher->fresh(['package', 'router']),
        ]);
    }

    /**
     * Bulk archive vouchers.
     */
    public function bulkArchive(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|string',
        ]);

        $ids = $validated['ids'];
        $tenantId = auth()->user()?->tenant_id;

        if (! $this->voucherSupportsArchiving()) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher archiving is not available until the archived_at column is deployed.',
            ], 409);
        }

        $archivedCount = 0;
        foreach ($ids as $id) {
            $voucher = Voucher::whereNull('archived_at')->find($id);
            if ($voucher) {
                $voucher->update(['archived_at' => now()]);
                broadcast(new VoucherUpdated($voucher->fresh(), $tenantId))->toOthers();
                $archivedCount++;
            }
        }

        $this->bustVoucherCache((string) $tenantId);

        return response()->json([
            'success' => true,
            'message' => "Archived {$archivedCount} voucher(s).",
            'data' => ['archived_count' => $archivedCount],
        ]);
    }

    /**
     * Export vouchers to CSV.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'required|string',
            'format' => 'nullable|string|in:csv',
        ]);

        $query = Voucher::with(['package:id,name,price,download_speed,validity'])
            ->orderBy('created_at', 'desc');

        if (!empty($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }

        $vouchers = $query->get();

        $headers = [
            'Code', 'Package', 'Price', 'Speed', 'Status',
            'Unused Expiry', 'Used At', 'Created At', 'Archived At', 'Notes',
        ];

        $csv = implode(',', array_map(function ($h) {
            return '"' . str_replace('"', '""', $h) . '"';
        }, $headers)) . "\n";

        $supportsArchiving = $this->voucherSupportsArchiving();

        foreach ($vouchers as $v) {
            $status = $supportsArchiving && $v->archived_at ? "{$v->status} (archived)" : $v->status;
            $row = [
                $v->code,
                $v->package?->name ?? '',
                $v->package?->price ?? '',
                $v->package?->download_speed ?? '',
                $status,
                $v->expires_at ? $v->expires_at->format('Y-m-d H:i:s') : '',
                $v->used_at ? $v->used_at->format('Y-m-d H:i:s') : '',
                $v->created_at ? $v->created_at->format('Y-m-d H:i:s') : '',
                $supportsArchiving && $v->archived_at ? $v->archived_at->format('Y-m-d H:i:s') : '',
                $v->notes ?? '',
            ];
            $csv .= implode(',', array_map(function ($cell) {
                return '"' . str_replace('"', '""', $cell) . '"';
            }, $row)) . "\n";
        }

        $filename = 'vouchers_' . now()->format('Y-m-d_His') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
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

    private function voucherSupportsArchiving(): bool
    {
        return Schema::hasColumn((new Voucher())->getTable(), 'archived_at');
    }

    private function voucherListColumns(): array
    {
        $columns = [
            'id', 'code', 'package_id', 'router_id', 'status', 'used_by',
            'used_at', 'expires_at', 'prefix', 'notes', 'batch_id',
            'archived_at', 'created_at', 'updated_at'
        ];

        if (! $this->voucherSupportsArchiving()) {
            return array_values(array_filter($columns, fn ($column) => $column !== 'archived_at'));
        }

        return $columns;
    }

    private function applyArchivedFilter($query, bool $includeArchived)
    {
        if (! $this->voucherSupportsArchiving()) {
            return $query;
        }

        return $includeArchived
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');
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
