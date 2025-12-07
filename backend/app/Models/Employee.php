<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

/**
 * Employee Model - TENANT SCHEMA ONLY
 *
 * This model operates in TENANT SCHEMAS (ts_xxxxx), NOT in public schema.
 * Multi-tenancy is enforced by PostgreSQL search_path, not by tenant_id column.
 * Each tenant's employees are completely isolated in their own schema.
 *
 * NO BelongsToTenant trait needed - schema isolation provides tenancy.
 */
class Employee extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'user_id',
        'employee_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'national_id',
        'date_of_birth',
        'gender',
        'address',
        'city',
        'postal_code',
        'department_id',
        'position_id',
        'employment_type',
        'hire_date',
        'contract_end_date',
        'employment_status',
        'salary',
        'salary_currency',
        'payment_frequency',
        'bank_name',
        'bank_account_number',
        'bank_branch',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'is_active',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'hire_date' => 'date',
        'contract_end_date' => 'date',
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = ['full_name'];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($employee) {
            if (empty($employee->employee_number)) {
                $employee->employee_number = static::generateEmployeeNumber($employee->first_name);
            }
        });

        static::saved(function ($employee) {
            // Update department employee count
            if ($employee->department_id) {
                $department = Department::find($employee->department_id);
                if ($department) {
                    $department->updateEmployeeCount();
                }
            }
        });

        static::deleted(function ($employee) {
            // Update department employee count
            if ($employee->department_id) {
                $department = Department::find($employee->department_id);
                if ($department) {
                    $department->updateEmployeeCount();
                }
            }
        });
    }

    /**
     * Generate a unique employee number
     * Format: FirstInitial (lowercase) + 8 digits (starts at 00000001)
     */
    protected static function generateEmployeeNumber($firstName)
    {
        $initial = strtolower(substr($firstName, 0, 1));
        
        $rangeStart = 1;
        $rangeEnd = 9999999;
        
        $lastEmployee = static::withTrashed()
            ->whereRaw("employee_number ~ '^[a-z]0[0-9]{7}$'")
            ->orderByRaw('CAST(SUBSTRING(employee_number, 2) AS INTEGER) DESC')
            ->first();

        if ($lastEmployee) {
            $lastNumber = (int) substr($lastEmployee->employee_number, 1);
            $newNumber = $lastNumber + 1;
            
            if ($newNumber > $rangeEnd) {
                throw new \Exception('Employee number range exhausted (00000001-09999999)');
            }
        } else {
            $newNumber = $rangeStart;
        }

        return $initial . str_pad($newNumber, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Get the employee's full name
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the user account (from public.users)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the department
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the position
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Scope to filter active employees
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where('employment_status', 'active');
    }

    /**
     * Scope to filter by department
     */
    public function scopeInDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Scope to filter by employment type
     */
    public function scopeByEmploymentType($query, $type)
    {
        return $query->where('employment_type', $type);
    }
}
