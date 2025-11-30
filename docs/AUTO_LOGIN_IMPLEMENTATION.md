# Auto-Login After Payment - Implementation Guide

## 🎯 Feature Overview

After successful M-Pesa payment, the user is automatically logged into the WiFi without manual credential entry. SMS with credentials is still sent for future use.

## 🔄 Complete Flow

```
1. User pays via M-Pesa
2. Payment callback received
3. CreateHotspotUserJob dispatched (database queue)
4. User account created + RADIUS provisioned
5. Credentials returned to frontend
6. Frontend auto-calls login API
7. User automatically connected
8. SMS sent in background with credentials
```

## 📝 Implementation Steps

### 1. Update PaymentController Callback

**File:** `backend/app/Http/Controllers/Api/PaymentController.php`

```php
public function callback(Request $request)
{
    // ... existing M-Pesa callback validation ...
    
    $payment = Payment::where('checkout_request_id', $checkoutRequestId)->first();
    
    if ($resultCode == 0) {
        // Payment successful
        $payment->update([
            'status' => 'completed',
            'mpesa_receipt_number' => $mpesaReceiptNumber,
            'transaction_date' => $transactionDate,
        ]);
        
        // Get package
        $package = Package::find($payment->package_id);
        
        // Dispatch job to create hotspot user (synchronous for auto-login)
        $credentials = $this->createHotspotUserSync($payment, $package);
        
        // Store credentials in cache for frontend retrieval
        Cache::put(
            "payment_credentials_{$payment->id}", 
            $credentials, 
            now()->addMinutes(5)
        );
        
        // Dispatch SMS job (async)
        SendCredentialsSMSJob::dispatch($credentials['hotspot_user_id']);
        
        // Broadcast event
        broadcast(new PaymentSuccessEvent($payment, $credentials));
        
    } else {
        // Payment failed
        $payment->update(['status' => 'failed']);
    }
    
    return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

/**
 * Create hotspot user synchronously for auto-login
 */
private function createHotspotUserSync($payment, $package)
{
    DB::beginTransaction();
    
    try {
        // Generate credentials
        $username = $payment->phone_number;
        $password = Str::random(12);
        
        // Create hotspot user
        $hotspotUser = HotspotUser::create([
            'username' => $username,
            'password' => bcrypt($password),
            'phone_number' => $payment->phone_number,
            'mac_address' => $payment->mac_address,
            'has_active_subscription' => true,
            'package_name' => $package->name,
            'package_id' => $package->id,
            'subscription_starts_at' => now(),
            'subscription_expires_at' => now()->addHours($package->duration_hours),
            'data_limit' => $package->data_limit_bytes,
            'is_active' => true,
            'status' => 'active',
        ]);
        
        // Create RADIUS entries
        DB::table('radcheck')->insert([
            'username' => $username,
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => $password,
        ]);
        
        DB::table('radreply')->insert([
            [
                'username' => $username,
                'attribute' => 'Session-Timeout',
                'op' => ':=',
                'value' => $package->duration_hours * 3600,
            ],
            [
                'username' => $username,
                'attribute' => 'ChilliSpot-Max-Total-Octets',
                'op' => ':=',
                'value' => $package->data_limit_bytes,
            ],
        ]);
        
        // Store credentials for SMS
        HotspotCredential::create([
            'hotspot_user_id' => $hotspotUser->id,
            'payment_id' => $payment->id,
            'username' => $username,
            'plain_password' => $password,
            'phone_number' => $payment->phone_number,
            'credentials_expires_at' => now()->addHours(24),
        ]);
        
        // Create initial radius session
        RadiusSession::create([
            'hotspot_user_id' => $hotspotUser->id,
            'payment_id' => $payment->id,
            'package_id' => $package->id,
            'username' => $username,
            'mac_address' => $payment->mac_address,
            'session_start' => now(),
            'expected_end' => now()->addHours($package->duration_hours),
            'status' => 'pending',
        ]);
        
        DB::commit();
        
        return [
            'hotspot_user_id' => $hotspotUser->id,
            'username' => $username,
            'password' => $password,
            'package_name' => $package->name,
            'expires_at' => $hotspotUser->subscription_expires_at,
        ];
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Failed to create hotspot user', [
            'error' => $e->getMessage(),
            'payment_id' => $payment->id,
        ]);
        throw $e;
    }
}
```

### 2. Add Payment Status Check Endpoint

**File:** `backend/routes/api.php`

```php
// Check payment status and get credentials
Route::get('/payments/{payment}/status', [PaymentController::class, 'checkStatus'])
    ->name('api.payments.status');
```

**File:** `backend/app/Http/Controllers/Api/PaymentController.php`

```php
public function checkStatus(Payment $payment)
{
    // Get credentials from cache if available
    $credentials = Cache::get("payment_credentials_{$payment->id}");
    
    return response()->json([
        'success' => true,
        'payment' => $payment,
        'credentials' => $credentials,
        'auto_login' => $credentials !== null,
    ]);
}
```

### 3. Update Frontend Payment Flow

**File:** `frontend/src/components/payment/PaymentModal.vue`

```vue
<script setup>
import { ref } from 'vue'
import axios from 'axios'

const pollPaymentStatus = async (paymentId) => {
  const maxAttempts = 60 // Poll for 60 seconds
  let attempts = 0
  
  const poll = async () => {
    try {
      const response = await axios.get(`/api/payments/${paymentId}/status`)
      
      if (response.data.payment.status === 'completed') {
        // Payment successful
        if (response.data.auto_login && response.data.credentials) {
          // Auto-login
          await autoLogin(response.data.credentials)
        } else {
          // Show success without auto-login
          showSuccessMessage('Payment successful! Check your SMS for login credentials.')
        }
        return true
      } else if (response.data.payment.status === 'failed') {
        showErrorMessage('Payment failed. Please try again.')
        return true
      }
      
      // Continue polling
      attempts++
      if (attempts < maxAttempts) {
        setTimeout(poll, 1000) // Poll every second
      } else {
        showErrorMessage('Payment verification timeout. Please check your SMS.')
      }
      
    } catch (error) {
      console.error('Payment status check error:', error)
      showErrorMessage('Error checking payment status')
    }
  }
  
  poll()
}

const autoLogin = async (credentials) => {
  try {
    showLoadingMessage('Connecting you to WiFi...')
    
    // Call login API
    const response = await axios.post('/api/hotspot/login', {
      username: credentials.username,
      password: credentials.password,
      mac_address: deviceMacAddress.value
    })
    
    if (response.data.success) {
      showSuccessNotification(
        `🎉 You're connected to WiFi! 
        Package: ${credentials.package_name}
        Valid until: ${formatDate(credentials.expires_at)}
        
        Credentials sent to your phone for future use.`,
        'success'
      )
      
      // Close modal
      emit('close')
      
      // Optional: Redirect to success page
      // router.push('/wifi-connected')
    } else {
      showErrorMessage('Auto-login failed. Please use credentials from SMS.')
    }
    
  } catch (error) {
    console.error('Auto-login error:', error)
    showErrorMessage('Auto-login failed. Please check your SMS for credentials.')
  }
}

const showLoadingMessage = (message) => {
  // Show loading toast
  showNotification(message, 'info')
}

const showSuccessMessage = (message) => {
  showNotification(message, 'success')
}

const showErrorMessage = (message) => {
  showNotification(message, 'error')
}
</script>
```

### 4. Update HotspotController Login

**File:** `backend/app/Http/Controllers/Api/HotspotController.php`

```php
public function login(Request $request)
{
    // ... existing validation ...
    
    try {
        $username = $request->input('username');
        $password = $request->input('password');
        $macAddress = $request->input('mac_address');

        // Find hotspot user
        $hotspotUser = HotspotUser::where('username', $username)->first();

        if (!$hotspotUser) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid username or password',
            ], 401);
        }

        // Verify password
        if (!password_verify($password, $hotspotUser->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid username or password',
            ], 401);
        }

        // Check subscription
        if (!$hotspotUser->has_active_subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription.',
            ], 403);
        }

        // Update or create radius session
        $radiusSession = RadiusSession::where('hotspot_user_id', $hotspotUser->id)
            ->where('status', 'pending')
            ->first();
            
        if (!$radiusSession) {
            $radiusSession = RadiusSession::create([
                'hotspot_user_id' => $hotspotUser->id,
                'username' => $username,
                'mac_address' => $macAddress,
                'ip_address' => $request->ip(),
                'session_start' => now(),
                'expected_end' => $hotspotUser->subscription_expires_at,
                'status' => 'active',
            ]);
        } else {
            $radiusSession->update([
                'status' => 'active',
                'ip_address' => $request->ip(),
            ]);
        }

        // Update last login
        $hotspotUser->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Log successful login
        Log::info('Hotspot user logged in', [
            'user_id' => $hotspotUser->id,
            'username' => $username,
            'auto_login' => $request->input('auto_login', false),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful. You are now connected to the internet.',
            'data' => [
                'user' => [
                    'id' => $hotspotUser->id,
                    'username' => $hotspotUser->username,
                    'phone_number' => $hotspotUser->phone_number,
                ],
                'session' => [
                    'id' => $radiusSession->id,
                    'session_start' => $radiusSession->session_start,
                    'expires_at' => $radiusSession->expected_end,
                ],
                'subscription' => [
                    'package_name' => $hotspotUser->package_name,
                    'expires_at' => $hotspotUser->subscription_expires_at,
                ],
            ],
        ], 200);

    } catch (\Exception $e) {
        Log::error('Hotspot login error', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'An error occurred during login.',
        ], 500);
    }
}
```

### 5. Update SMS Message

**File:** `backend/app/Jobs/SendCredentialsSMSJob.php`

```php
public function handle()
{
    $hotspotUser = HotspotUser::find($this->hotspotUserId);
    $credential = HotspotCredential::where('hotspot_user_id', $this->hotspotUserId)
                                  ->where('sms_sent', false)
                                  ->first();
    
    if (!$credential) {
        return;
    }
    
    try {
        // Updated SMS message
        $message = sprintf(
            "WiFi Credentials - Username: %s, Password: %s. Valid for: %s. You are already connected! Use these credentials if you disconnect or on other devices.",
            $credential->username,
            $credential->plain_password,
            $hotspotUser->package_name
        );
        
        $messageId = $this->sendSMS($credential->phone_number, $message);
        $credential->markSmsSent($messageId, 'sent');
        
        Log::info('Credentials SMS sent', [
            'user_id' => $this->hotspotUserId,
            'phone' => $credential->phone_number,
        ]);
        
    } catch (\Exception $e) {
        Log::error('Failed to send credentials SMS', [
            'error' => $e->getMessage(),
            'user_id' => $this->hotspotUserId,
        ]);
        throw $e;
    }
}
```

## 📊 Updated Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│ 1. User Selects Package & Pays                          │
└─────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────┐
│ 2. M-Pesa Callback → Payment Successful                 │
└─────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────┐
│ 3. Create Hotspot User (Synchronous)                    │
│    - Generate credentials                                │
│    - Create RADIUS entries                               │
│    - Store in cache                                      │
└─────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────┐
│ 4. Frontend Polls Payment Status                        │
│    - Gets credentials from cache                         │
└─────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────┐
│ 5. Frontend Auto-Calls Login API                        │
│    - POST /api/hotspot/login                             │
│    - With credentials from cache                         │
└─────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────┐
│ 6. User Automatically Connected!                        │
│    - Show success message                                │
│    - Display package details                             │
└─────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────┐
│ 7. SMS Sent in Background (Async Job)                   │
│    - "You are already connected!"                        │
│    - Credentials for future use                          │
└─────────────────────────────────────────────────────────┘
```

## ✅ Benefits

1. **Seamless UX** - User doesn't need to manually enter credentials
2. **Instant Access** - Connected immediately after payment
3. **Backup Credentials** - SMS still sent for future use
4. **Multi-Device Support** - Can use credentials on other devices
5. **Reduced Support** - Less confusion about login process

## 🔒 Security Considerations

1. **Cache Expiry** - Credentials cached for only 5 minutes
2. **One-Time Use** - Credentials removed from cache after retrieval
3. **MAC Address Binding** - Session tied to device MAC
4. **Password Hashing** - Stored passwords are hashed
5. **HTTPS Required** - All API calls over HTTPS

## 📝 Configuration

```env
# Auto-Login Settings
HOTSPOT_AUTO_LOGIN_ENABLED=true
HOTSPOT_CREDENTIALS_CACHE_TTL=300 # 5 minutes
HOTSPOT_PAYMENT_POLL_TIMEOUT=60 # seconds

# Queue Settings
QUEUE_CONNECTION=database
```

## ✅ Testing Checklist

- [ ] Payment successful → User auto-logged in
- [ ] SMS sent with credentials
- [ ] Credentials work on manual login
- [ ] Cache expires after 5 minutes
- [ ] Multiple devices can use same credentials
- [ ] Session tracked correctly
- [ ] RADIUS authentication works
- [ ] Data usage tracked

---

**Status:** Ready for implementation  
**Queue:** Database  
**Auto-Login:** Enabled  
**SMS:** Background delivery
