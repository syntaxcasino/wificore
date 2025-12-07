<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid;

/**
 * Department Model - TENANT SCHEMA ONLY
 *
 * This model operates in TENANT SCHEMAS (ts_xxxxx), NOT in public schema.
 * Multi-tenancy is enforced by PostgreSQL search_path, not by tenant_id column.
 * Each tenant's departments are completely isolated in their own schema.
 *
 * NO BelongsToTenant trait needed - schema isolation provides tenancy.
 */
class Department extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'name',
        'code',
        'description',
        'manager_id',
        'budget',
        'location',
        'status',
        'is_active',
        'employee_count',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'is_active' => 'boolean',
        'employee_count' => 'integer',
    ];

    /**
     * Get the manager of the department
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Get all employees in this department
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get all positions in this department
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Scope to filter active departments
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where('status', 'active');
    }

    /**
     * Update employee count
     */
    public function updateEmployeeCount()
    {
        $this->employee_count = $this->employees()->count();
        $this->save();
    }
}
