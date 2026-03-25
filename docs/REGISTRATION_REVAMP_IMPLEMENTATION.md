# Registration Revamp Implementation Guide

## Overview

This document outlines the comprehensive changes made to implement the new tenant registration flow with email verification, auto-generated credentials, and multi-step visual feedback.

## Completed Changes

### 1. Walled Garden Configuration ✅

**File:** `backend/app/Services/MikroTik/SecurityHardeningService.php`

Updated walled garden to use `*.wificore.traidsolutions.com` pattern:
```php
$hosts = [
    '*.wificore.traidsolutions.com' => 'Captive Portal - All Tenant Subdomains',
    'wificore.traidsolutions.com' => 'Captive Portal - Main Domain',
    // ... CDN domains
];
```

### 2. Email Verification System ✅

**Created Files:**
- `backend/app/Notifications/TenantEmailVerification.php` - Sends verification email
- `backend/app/Notifications/TenantCredentialsEmail.php` - Sends credentials after verification
- `backend/app/Http/Controllers/Api/EmailVerificationController.php` - Handles verification
- `backend/database/migrations/2025_12_16_135937_add_email_verified_at_to_tenants_table.php`

**Updated Files:**
- `backend/app/Models/Tenant.php` - Added Notifiable trait, email_verified_at field
- `backend/routes/api.php` - Added verification routes

### 3. Registration Controller Revamp ✅

**File:** `backend/app/Http/Controllers/Api/TenantRegistrationController.php`

**Changes:**
- Removed all personal fields (name, email, username, password)
- Only requires company details: name, email, phone, address
- Auto-generates username from company slug (without hyphens)
- Auto-generates secure 12-character password
- Creates tenant immediately (synchronous)
- Sends verification email
- Tenant inactive until email verified

**New Flow:**
1. User submits company details
2. System creates tenant (inactive)
3. System generates username & password
4. System sends verification email
5. User clicks verification link
6. System creates schema & admin user (async job)
7. System sends credentials email
8. Tenant becomes active

### 4. CreateTenantJob Updates ✅

**File:** `backend/app/Jobs/CreateTenantJob.php`

- Added credential email sending after successful creation
- Marks tenant as active after completion
- Updates tenant settings with credentials_sent flag

## Remaining Tasks

### 1. Frontend Registration Component (HIGH PRIORITY)

**File:** `frontend/src/modules/common/views/auth/TenantRegistrationView.vue`

**Requirements:**
- Remove fields: Full Name, Email, Username, Password, Confirm Password
- Keep only: Company Name, Company Email, Company Phone, Company Address
- Remove "(optional)" text from all fields
- Add 3-step progress indicator at top:
  - Step 1: Input & Submission
  - Step 2: Email Verification
  - Step 3: Sending Credentials

**Visual Feedback:**
- Step 1: Show form, on submit show "Processing..."
- After submit success: Check step 1, move to step 2
- Step 2: Show "Check your email" message, poll for verification status
- After email verified: Check step 2, move to step 3
- Step 3: Show "Processing...", poll for credentials sent
- After credentials sent: Show "Done!", display login instructions

**Implementation:**
```vue
<template>
  <div class="registration-container">
    <!-- Step Indicator -->
    <div class="steps">
      <div class="step" :class="{ active: currentStep >= 1, completed: currentStep > 1 }">
        <div class="step-number">1</div>
        <div class="step-label">Input & Submission</div>
      </div>
      <div class="step" :class="{ active: currentStep >= 2, completed: currentStep > 2 }">
        <div class="step-number">2</div>
        <div class="step-label">Email Verification</div>
      </div>
      <div class="step" :class="{ active: currentStep >= 3, completed: currentStep > 3 }">
        <div class="step-number">3</div>
        <div class="step-label">Sending Credentials</div>
      </div>
    </div>

    <!-- Step 1: Form -->
    <div v-if="currentStep === 1" class="form-step">
      <form @submit.prevent="handleSubmit">
        <h2>Company Details</h2>
        
        <div class="form-group">
          <label>Company Name</label>
          <input v-model="form.company_name" required />
        </div>
        
        <div class="form-group">
          <label>Company Email</label>
          <input v-model="form.company_email" type="email" required />
        </div>
        
        <div class="form-group">
          <label>Company Phone</label>
          <input v-model="form.company_phone" required />
        </div>
        
        <div class="form-group">
          <label>Company Address</label>
          <input v-model="form.company_address" required />
        </div>
        
        <div class="form-group">
          <input type="checkbox" v-model="form.accept_terms" required />
          <label>I agree to Terms & Conditions</label>
        </div>
        
        <button type="submit" :disabled="submitting">
          {{ submitting ? 'Processing...' : 'Register' }}
        </button>
      </form>
    </div>

    <!-- Step 2: Email Verification -->
    <div v-if="currentStep === 2" class="verification-step">
      <h2>Check Your Email</h2>
      <p>We've sent a verification link to {{ registrationData.email }}</p>
      <p>Please click the link to verify your email address.</p>
      <div class="spinner">Waiting for verification...</div>
    </div>

    <!-- Step 3: Creating Account -->
    <div v-if="currentStep === 3" class="processing-step">
      <h2>Creating Your Account</h2>
      <p>Please wait while we set up your workspace...</p>
      <div class="spinner">Processing...</div>
    </div>

    <!-- Step 4: Complete -->
    <div v-if="currentStep === 4" class="complete-step">
      <h2>✅ Done!</h2>
      <p>Your account has been created successfully!</p>
      <p>Check your email for login credentials.</p>
      <button @click="goToLogin">Go to Login</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const currentStep = ref(1)
const submitting = ref(false)
const registrationData = ref(null)

const form = ref({
  company_name: '',
  company_email: '',
  company_phone: '',
  company_address: '',
  accept_terms: false
})

const handleSubmit = async () => {
  submitting.value = true
  try {
    const response = await axios.post('/register/tenant', form.value)
    registrationData.value = response.data.data
    currentStep.value = 2
    startPollingVerification()
  } catch (error) {
    alert('Registration failed: ' + error.response?.data?.message)
  } finally {
    submitting.value = false
  }
}

const startPollingVerification = () => {
  const pollInterval = setInterval(async () => {
    try {
      const response = await axios.get(`/register/status/${registrationData.value.tenant_id}`)
      const status = response.data.data
      
      if (status.email_verified && currentStep.value === 2) {
        currentStep.value = 3
      }
      
      if (status.credentials_sent && currentStep.value === 3) {
        currentStep.value = 4
        clearInterval(pollInterval)
      }
    } catch (error) {
      console.error('Status check failed:', error)
    }
  }, 3000) // Poll every 3 seconds
}

const goToLogin = () => {
  router.push('/login')
}
</script>
```

### 2. IP Block Allocation System

**Create:** `backend/app/Services/IpBlockAllocationService.php`

```php
<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class IpBlockAllocationService
{
    // Base network for tenant allocations
    private const BASE_NETWORK = '10.0.0.0';
    private const SUBNET_MASK = 16; // /16 gives 65,536 IPs per tenant
    
    /**
     * Allocate unique IP block to tenant
     */
    public function allocateTenantIpBlock(Tenant $tenant): array
    {
        // Get next available block
        $lastBlock = DB::table('tenants')
            ->whereNotNull('settings->ip_block')
            ->orderByRaw("CAST(settings->'ip_block'->>'block_number' AS INTEGER) DESC")
            ->first();
        
        $blockNumber = $lastBlock ? 
            (int)json_decode($lastBlock->settings)->ip_block->block_number + 1 : 1;
        
        // Calculate network address
        // Block 1: 10.1.0.0/16
        // Block 2: 10.2.0.0/16
        // etc.
        $networkAddress = "10.{$blockNumber}.0.0";
        $gatewayAddress = "10.{$blockNumber}.0.1";
        $dhcpStart = "10.{$blockNumber}.0.10";
        $dhcpEnd = "10.{$blockNumber}.255.254";
        
        $ipBlock = [
            'block_number' => $blockNumber,
            'network' => "{$networkAddress}/{$this::SUBNET_MASK}",
            'gateway' => $gatewayAddress,
            'dhcp_range' => "{$dhcpStart}-{$dhcpEnd}",
            'allocated_at' => now()->toIso8601String(),
        ];
        
        // Update tenant settings
        $tenant->update([
            'settings' => array_merge($tenant->settings, [
                'ip_block' => $ipBlock
            ])
        ]);
        
        return $ipBlock;
    }
}
```

**Update:** `backend/app/Jobs/CreateTenantJob.php`

Add IP block allocation:
```php
// After tenant creation, before schema creation
$ipBlockService = app(\App\Services\IpBlockAllocationService::class);
$ipBlock = $ipBlockService->allocateTenantIpBlock($tenant);

Log::info('IP block allocated to tenant', [
    'tenant_id' => $tenant->id,
    'ip_block' => $ipBlock,
]);
```

### 3. Tenant Login Redirect Fix

**File:** `backend/app/Http/Controllers/Api/UnifiedAuthController.php`

**Current Issue:** Tenant must login with subdomain
**Required Fix:** Allow login without subdomain, redirect to subdomain after authentication

```php
public function login(Request $request)
{
    // ... existing validation ...
    
    // After successful authentication
    if ($user->role === User::ROLE_ADMIN && $user->tenant_id) {
        $tenant = Tenant::find($user->tenant_id);
        
        // Return subdomain for frontend redirect
        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user,
            'redirect_subdomain' => $tenant->slug . '.wificore.traidsolutions.com',
        ]);
    }
    
    // System admin - no subdomain redirect
    if ($user->role === User::ROLE_SYSTEM_ADMIN) {
        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user,
            'redirect_subdomain' => null, // No redirect for system admin
        ]);
    }
}
```

**Frontend:** `frontend/src/modules/common/views/auth/LoginView.vue`

```javascript
const handleLogin = async () => {
  const response = await axios.post('/login', credentials)
  
  if (response.data.redirect_subdomain) {
    // Redirect to tenant subdomain
    window.location.href = `https://${response.data.redirect_subdomain}/dashboard`
  } else {
    // System admin - stay on main domain
    router.push('/dashboard')
  }
}
```

### 4. Hotspot Default Page

**File:** `frontend/src/router/index.js`

Change hotspot root path from `/` to `/login`:

```javascript
const routes = [
  // Hotspot public pages
  { path: '/hotspot', redirect: '/hotspot/login' },
  { path: '/hotspot/login', name: 'hotspot-login', component: HotspotLoginView },
  
  // Main app
  { path: '/', name: 'home', redirect: '/login' },
  { path: '/login', name: 'login', component: LoginView },
  // ... rest of routes
]
```

## Database Migration

Run migration to add email_verified_at column:

```bash
cd backend
php artisan migrate
```

## Testing Checklist

- [ ] Register new tenant with only company details
- [ ] Verify email verification email is sent
- [ ] Click verification link
- [ ] Verify credentials email is sent after schema creation
- [ ] Login with generated credentials
- [ ] Verify tenant is redirected to subdomain
- [ ] Verify system admin is NOT redirected
- [ ] Verify unique IP block allocated to tenant
- [ ] Verify hotspot redirects to /login by default
- [ ] Verify walled garden allows *.wificore.traidsolutions.com

## Deployment Steps

1. Commit all backend changes
2. Run database migration
3. Update frontend registration component
4. Rebuild Docker containers
5. Test registration flow end-to-end
6. Push to production

## Notes

- Username format: company slug without hyphens (e.g., "mycompany" from "my-company")
- Password: 12-character secure random (uppercase, lowercase, numbers, special chars)
- Email verification link expires in 60 minutes
- Tenant inactive until email verified
- IP blocks allocated sequentially: 10.1.0.0/16, 10.2.0.0/16, etc.
- Each tenant gets 65,536 IP addresses
