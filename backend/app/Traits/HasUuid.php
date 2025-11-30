<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * UUID Trait for Laravel Models
 * 
 * This trait automatically generates UUIDs for models instead of auto-incrementing integers.
 * 
 * Usage:
 * 1. Add 'use HasUuid;' to your model class
 * 2. Ensure your database column is UUID type
 * 3. Set $keyType = 'string' and $incrementing = false (handled by trait)
 */
trait HasUuid
{
    /**
     * Boot the UUID trait for the model.
     * 
     * Automatically generates a UUID when creating a new model instance.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            // Only generate UUID if not already set
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Initialize the UUID trait for an instance.
     * 
     * Sets the key type to string for UUID compatibility.
     */
    public function initializeHasUuid(): void
    {
        // Ensure ID is cast to string
        $this->casts[$this->getKeyName()] = 'string';
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     * 
     * @return bool
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Get the auto-incrementing key type.
     * 
     * @return string
     */
    public function getKeyType(): string
    {
        return 'string';
    }

    /**
     * Get the route key for the model.
     * 
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }
}
