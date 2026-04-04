<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasUuid;
use Illuminate\Support\Facades\Crypt;

/**
 * Canary Deployment Model
 * 
 * Tracks gradual rollout of configuration changes with health monitoring.
 */
class CanaryDeployment extends Model
{
    use HasFactory, HasUuid;
    protected $table = 'canary_deployments';

    protected $fillable = [
        'config_version',
        'config_hash',
        'total_routers',
        'canary_count',
        'canary_routers',
        'remaining_routers',
        'percentage',
        'status',
        'health_check_interval',
        'health_score',
        'config_content',
        'started_at',
        'promoted_at',
        'rolled_back_at',
        'completed_at',
        'last_health_check',
    ];

    protected $casts = [
        'canary_routers' => 'array',
        'remaining_routers' => 'array',
        'health_score' => 'float',
        'started_at' => 'datetime',
        'promoted_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_health_check' => 'datetime',
    ];

    /**
     * Set config_content with encryption
     */
    public function setConfigContentAttribute(string $value): void
    {
        $this->attributes['config_content'] = Crypt::encryptString($value);
    }

    /**
     * Get decrypted config content
     */
    public function getDecryptedConfig(): string
    {
        return Crypt::decryptString($this->config_content);
    }

    /**
     * Check if deployment is in canary phase
     */
    public function isCanaryRunning(): bool
    {
        return $this->status === 'canary_running';
    }

    /**
     * Check if deployment is completed
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'auto_rolled_back']);
    }
}
