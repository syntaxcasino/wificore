<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

/**
 * Todo Model - TENANT SCHEMA ONLY
 * 
 * This model operates in TENANT SCHEMAS (ts_xxxxx), NOT in public schema.
 * Multi-tenancy is enforced by PostgreSQL search_path, not by tenant_id column.
 * Each tenant's todos are completely isolated in their own schema.
 * 
 * NO BelongsToTenant trait needed - schema isolation provides tenancy.
 */
class Todo extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'user_id',
        'created_by',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'completed_at',
        'related_type',
        'related_id',
        'metadata',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'created_by' => 'string',
        'related_id' => 'string',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'deleted_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the user this todo is assigned to
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who created this todo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the related model (polymorphic)
     */
    public function related()
    {
        return $this->morphTo();
    }

    /**
     * Get the activities for this todo
     */
    public function activities()
    {
        return $this->hasMany(TodoActivity::class)->orderBy('created_at', 'desc');
    }

    /**
     * Scope for pending todos
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for in progress todos
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope for completed todos
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for overdue todos
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', '!=', 'completed')
            ->where('due_date', '<', now());
    }

    /**
     * Scope for assigned to user
     */
    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for created by user
     */
    public function scopeCreatedBy($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted()
    {
        $this->status = 'completed';
        $this->completed_at = now();
        return $this->save();
    }

    /**
     * Mark as in progress
     */
    public function markAsInProgress()
    {
        $this->status = 'in_progress';
        return $this->save();
    }

    /**
     * Check if todo is overdue
     */
    public function isOverdue(): bool
    {
        return $this->status !== 'completed' 
            && $this->due_date 
            && $this->due_date->isPast();
    }

    /**
     * Check if todo is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if todo is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if todo is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
