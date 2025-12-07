<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

/**
 * Revenue Model - TENANT SCHEMA ONLY
 *
 * This model operates in TENANT SCHEMAS (ts_xxxxx), NOT in public schema.
 * Multi-tenancy is enforced by PostgreSQL search_path, not by tenant_id column.
 * Each tenant's revenues are completely isolated in their own schema.
 *
 * NO BelongsToTenant trait needed - schema isolation provides tenancy.
 */
class Revenue extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'revenue_number',
        'source',
        'description',
        'amount',
        'revenue_date',
        'payment_method',
        'reference_number',
        'customer_id',
        'recorded_by',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'revenue_date' => 'date',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($revenue) {
            if (empty($revenue->revenue_number)) {
                $revenue->revenue_number = static::generateRevenueNumber();
            }
        });
    }

    /**
     * Generate a unique revenue number
     * Format: REV-YYYYMMDD-XXXX
     */
    protected static function generateRevenueNumber()
    {
        $date = now()->format('Ymd');
        $prefix = "REV-{$date}-";
        
        $lastRevenue = static::withTrashed()
            ->where('revenue_number', 'LIKE', "{$prefix}%")
            ->orderBy('revenue_number', 'desc')
            ->first();

        if ($lastRevenue) {
            $lastNumber = (int) substr($lastRevenue->revenue_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the customer (from public.users)
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Get the user who recorded the revenue (from public.users)
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by source
     */
    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('revenue_date', [$startDate, $endDate]);
    }

    /**
     * Confirm the revenue
     */
    public function confirm()
    {
        $this->update(['status' => 'confirmed']);
    }

    /**
     * Cancel the revenue
     */
    public function cancel()
    {
        $this->update(['status' => 'cancelled']);
    }
}
