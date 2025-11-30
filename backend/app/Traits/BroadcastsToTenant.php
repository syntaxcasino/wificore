<?php

namespace App\Traits;

use Illuminate\Broadcasting\PrivateChannel;

trait BroadcastsToTenant
{
    /**
     * Get tenant-specific private channel
     * 
     * @param string $channelName The channel name (e.g., 'admin-notifications')
     * @return PrivateChannel Tenant-scoped channel
     */
    protected function getTenantChannel(string $channelName): PrivateChannel
    {
        $tenantId = $this->getTenantId();
        return new PrivateChannel("tenant.{$tenantId}.{$channelName}");
    }

    /**
     * Get multiple tenant-specific channels
     * 
     * @param array $channelNames Array of channel names
     * @return array Array of PrivateChannel instances
     */
    protected function getTenantChannels(array $channelNames): array
    {
        $tenantId = $this->getTenantId();
        
        return array_map(function($channelName) use ($tenantId) {
            return new PrivateChannel("tenant.{$tenantId}.{$channelName}");
        }, $channelNames);
    }

    /**
     * Get tenant ID from the event's data
     * Tries multiple sources to find tenant_id
     * 
     * @return string The tenant ID
     * @throws \Exception If tenant ID cannot be determined
     */
    protected function getTenantId(): string
    {
        // Try to get tenant_id from payment
        if (isset($this->payment) && $this->payment->tenant_id) {
            return $this->payment->tenant_id;
        }
        
        // Try to get tenant_id from user
        if (isset($this->user) && $this->user->tenant_id) {
            return $this->user->tenant_id;
        }
        
        // Try to get tenant_id from router
        if (isset($this->router) && $this->router->tenant_id) {
            return $this->router->tenant_id;
        }
        
        // Try to get tenant_id from package
        if (isset($this->package) && $this->package->tenant_id) {
            return $this->package->tenant_id;
        }
        
        // Try to get tenant_id from subscription
        if (isset($this->subscription) && $this->subscription->tenant_id) {
            return $this->subscription->tenant_id;
        }
        
        // Try to get tenant_id from hotspot user
        if (isset($this->hotspotUser) && $this->hotspotUser->tenant_id) {
            return $this->hotspotUser->tenant_id;
        }
        
        // Try direct tenantId property
        if (isset($this->tenantId)) {
            return $this->tenantId;
        }
        
        throw new \Exception('Cannot determine tenant ID for broadcasting. Event: ' . get_class($this));
    }

    /**
     * Check if a user should receive this broadcast
     * 
     * @param \App\Models\User $user The user to check
     * @return bool True if user should receive broadcast
     */
    protected function shouldBroadcastToUser($user): bool
    {
        // System admins can see all events
        if ($user->isSystemAdmin()) {
            return true;
        }
        
        // Check tenant match
        try {
            $tenantId = $this->getTenantId();
            return $user->tenant_id === $tenantId;
        } catch (\Exception $e) {
            // If we can't determine tenant, deny access
            return false;
        }
    }

    /**
     * Mask phone number for privacy
     * 
     * @param string $phone The phone number to mask
     * @return string Masked phone number
     */
    protected function maskPhoneNumber(string $phone): string
    {
        if (strlen($phone) < 4) {
            return '****';
        }
        
        return substr($phone, 0, 3) . str_repeat('*', strlen($phone) - 5) . substr($phone, -2);
    }

    /**
     * Mask email for privacy
     * 
     * @param string $email The email to mask
     * @return string Masked email
     */
    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        
        if (count($parts) !== 2) {
            return '***@***.***';
        }
        
        $username = $parts[0];
        $domain = $parts[1];
        
        $maskedUsername = strlen($username) > 2 
            ? substr($username, 0, 2) . str_repeat('*', strlen($username) - 2)
            : str_repeat('*', strlen($username));
        
        return $maskedUsername . '@' . $domain;
    }

    /**
     * Get sanitized user data for broadcasting
     * Removes sensitive information
     * 
     * @param \App\Models\User $user The user
     * @return array Sanitized user data
     */
    protected function getSanitizedUserData($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'phone_number' => $this->maskPhoneNumber($user->phone_number ?? ''),
            'email' => $this->maskEmail($user->email),
            // Never include: password, remember_token, api_tokens
        ];
    }

    /**
     * Get sanitized payment data for broadcasting
     * Removes/masks sensitive information
     * 
     * @param \App\Models\Payment $payment The payment
     * @return array Sanitized payment data
     */
    protected function getSanitizedPaymentData($payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'phone_number' => $this->maskPhoneNumber($payment->phone_number),
            'transaction_id' => substr($payment->transaction_id, 0, 8) . '...',
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
            'created_at' => $payment->created_at->toIso8601String(),
            // Never include: full transaction_id, callback_response
        ];
    }
}
