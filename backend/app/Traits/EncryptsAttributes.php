<?php

namespace App\Traits;

use Illuminate\Support\Facades\Crypt;

/**
 * Trait to automatically encrypt/decrypt sensitive model attributes
 */
trait EncryptsAttributes
{
    /**
     * Get the list of attributes that should be encrypted
     * Override this in your model
     *
     * @return array
     */
    public function getEncryptedAttributes(): array
    {
        return property_exists($this, 'encrypted') ? $this->encrypted : [];
    }

    /**
     * Encrypt an attribute value
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->getEncryptedAttributes()) && !is_null($value)) {
            $value = Crypt::encryptString($value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Decrypt an attribute value
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (in_array($key, $this->getEncryptedAttributes()) && !is_null($value)) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Exception $e) {
                // If decryption fails, return original value
                // This handles cases where data was not encrypted
                \Log::warning('Failed to decrypt attribute', [
                    'model' => get_class($this),
                    'attribute' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $value;
    }

    /**
     * Get array representation with encrypted fields
     *
     * @return array
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        foreach ($this->getEncryptedAttributes() as $key) {
            if (isset($attributes[$key])) {
                try {
                    $attributes[$key] = Crypt::decryptString($attributes[$key]);
                } catch (\Exception $e) {
                    // Keep original value if decryption fails
                }
            }
        }

        return $attributes;
    }
}
