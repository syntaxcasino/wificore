<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\Package;
use App\Events\VoucherCreated;
use App\Events\VoucherUpdated;
use App\Events\VoucherDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    /**
     * List all vouchers with optional filters.
     */
    public function index(Request $request)
    {
        $query = Voucher::with(['package:id,name,price,download_speed,validity', 'router:id,name'])
            ->orderBy('created_at', 'desc');

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
            $query->where('code', 'ilike', '%' . $request->search . '%');
        }

        $perPage = $request->input('per_page', 25);
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
        $voucher->load(['package', 'router', 'usedBy']);

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

        $batchId = Str::uuid()->toString();
        $vouchers = [];

        for ($i = 0; $i < $validated['quantity']; $i++) {
            $code = $this->generateUniqueCode($validated['prefix'] ?? '');

            $voucher = Voucher::create([
                'code' => $code,
                'package_id' => $validated['package_id'],
                'router_id' => $validated['router_id'] ?? null,
                'status' => 'unused',
                'expires_at' => $validated['expires_at'] ?? null,
                'prefix' => $validated['prefix'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'batch_id' => $batchId,
            ]);

            $vouchers[] = $voucher;

            // Broadcast event for each voucher created
            $tenantId = $request->user()->tenant_id;
            broadcast(new VoucherCreated($voucher, $tenantId))->toOthers();
        }

        // Load relationships for the response
        $vouchers = Voucher::with(['package:id,name,price,download_speed,validity'])
            ->where('batch_id', $batchId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => "Successfully generated {$validated['quantity']} voucher(s).",
            'data' => [
                'batch_id' => $batchId,
                'count' => $vouchers->count(),
                'vouchers' => $vouchers,
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

        // Broadcast event
        $tenantId = auth()->user()?->tenant_id;
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
        $stats = [
            'total' => Voucher::count(),
            'unused' => Voucher::where('status', 'unused')->count(),
            'used' => Voucher::where('status', 'used')->count(),
            'expired' => Voucher::where('status', 'expired')->count(),
            'revoked' => Voucher::where('status', 'revoked')->count(),
        ];

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
        $batches = Voucher::selectRaw("
                batch_id,
                COUNT(*) as quantity,
                MIN(created_at) as created_at,
                MAX(package_id) as package_id
            ")
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->orderByRaw('MIN(created_at) DESC')
            ->limit($request->input('limit', 10))
            ->get();

        // Load package names
        $packageIds = $batches->pluck('package_id')->unique();
        $packages = Package::whereIn('id', $packageIds)->pluck('name', 'id');

        $result = $batches->map(function ($batch) use ($packages) {
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
        });

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Generate a unique voucher code.
     */
    private function generateUniqueCode(string $prefix = ''): string
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

            if (!Voucher::where('code', $code)->exists()) {
                return $code;
            }
        }

        // Fallback: append UUID fragment
        return ($prefix ? strtoupper($prefix) . '-' : '') . strtoupper(Str::random(12));
    }
}
