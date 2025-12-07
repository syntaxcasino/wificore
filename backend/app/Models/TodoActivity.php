<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class TodoActivity extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'todo_id',
        'user_id',
        'action',
        'old_value',
        'new_value',
        'description',
    ];

    protected $casts = [
        'id' => 'string',
        'todo_id' => 'string',
        'user_id' => 'string',
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    /**
     * Get the todo this activity belongs to
     */
    public function todo()
    {
        return $this->belongsTo(Todo::class);
    }

    /**
     * Get the user who performed this activity
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for specific action
     */
    public function scopeAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope for specific user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get formatted description
     */
    public function getFormattedDescriptionAttribute(): string
    {
        if ($this->description) {
            return $this->description;
        }

        // Generate description based on action
        $userName = $this->user->name ?? 'Unknown';
        
        return match($this->action) {
            'created' => "{$userName} created this todo",
            'updated' => "{$userName} updated this todo",
            'completed' => "{$userName} marked this todo as completed",
            'assigned' => "{$userName} assigned this todo",
            'deleted' => "{$userName} deleted this todo",
            default => "{$userName} performed an action",
        };
    }
}
