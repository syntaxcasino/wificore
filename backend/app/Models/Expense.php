<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

/**
 * Expense Model - TENANT SCHEMA ONLY
 *
 * This model operates in TENANT SCHEMAS (ts_xxxxx), NOT in public schema.
 * Multi-tenancy is enforced by PostgreSQL search_path, not by tenant_id column.
 * Each tenant's expenses are completely isolated in their own schema.
 *
 * NO BelongsToTenant trait needed - schema isolation provides tenancy.
 */
class Expense extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'expense_number',
        'category',
        'description',
        'amount',
        'expense_date',
        'payment_method',
        'vendor_name',
        'receipt_number',
        'receipt_file',
        'submitted_by',
        'approved_by',
        'approved_at',
        'status',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($expense) {
            if (empty($expense->expense_number)) {
                $expense->expense_number = static::generateExpenseNumber();
            }
        });
    }

    /**
     * Generate a unique expense number
     * Format: EXP-YYYYMMDD-XXXX
     */
    protected static function generateExpenseNumber()
    {
        $date = now()->format('Ymd');
        $prefix = "EXP-{$date}-";
        
        $lastExpense = static::withTrashed()
            ->where('expense_number', 'LIKE', "{$prefix}%")
            ->orderBy('expense_number', 'desc')
            ->first();

        if ($lastExpense) {
            $lastNumber = (int) substr($lastExpense->expense_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the user who submitted the expense (from public.users)
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Get the user who approved the expense (from public.users)
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('expense_date', [$startDate, $endDate]);
    }

    /**
     * Approve the expense
     */
    public function approve($userId)
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
    }

    /**
     * Reject the expense
     */
    public function reject($userId, $reason)
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $userId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Mark as paid
     */
    public function markAsPaid()
    {
        $this->update(['status' => 'paid']);
    }
}
