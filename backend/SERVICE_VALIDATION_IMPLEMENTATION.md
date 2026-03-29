# Service Validation Implementation Guide

**This document provides the exact code to add to each service**

---

## 🔴 **PHASE 1: CRITICAL SERVICES**

### **1. SubscriptionManager.php**

Add this to the class:

```php
public function createSubscription(User $user, Package $package): UserSubscription
{
    $tenantId = $this->getTenantId();
    
    // Validate both belong to same tenant
    $this->validateUser($user, $tenantId);
    $this->validatePackage($package, $tenantId);
    
    // Create subscription
    $subscription = UserSubscription::create([
        'tenant_id' => $tenantId,
        'user_id' => $user->id,
        'package_id' => $package->id,
        'starts_at' => now(),
        'expires_at' => now()->addHours($package->duration),
    ]);
    
    $this->logTenantOperation('create_subscription', [
        'user_id' => $user->id,
        'package_id' => $package->id
    ], $tenantId);
    
    return $subscription;
}

public function renewSubscription(UserSubscription $subscription): UserSubscription
{
    $tenantId = $this->getTenantId();
    
    // Validate subscription belongs to tenant
    if ($subscription->tenant_id !== $tenantId) {
        throw new \Exception('Subscription does not belong to this tenant');
    }
    
    // Renew
    $subscription->update([
        'expires_at' => now()->addHours($subscription->package->duration)
    ]);
    
    $this->logTenantOperation('renew_subscription', [
        'subscription_id' => $subscription->id
    ], $tenantId);
    
    return $subscription;
}
```

---

### **2. MpesaService.php**

Add this to the class:

```php
public function initiatePayment(Package $package, string $phoneNumber, string $macAddress): array
{
    $tenantId = $this->getTenantId();
    
    // Validate package belongs to tenant
    $this->validatePackage($package, $tenantId);
    
    // Create payment record with tenant_id
    $payment = Payment::create([
        'tenant_id' => $tenantId,
        'package_id' => $package->id,
        'phone_number' => $phoneNumber,
        'mac_address' => $macAddress,
        'amount' => $package->price,
        'status' => 'pending',
        'transaction_id' => 'MPESA-' . time() . '-' . rand(1000, 9999),
    ]);
    
    // Initiate M-Pesa STK push
    $response = $this->sendSTKPush($payment);
    
    $this->logTenantOperation('initiate_payment', [
        'payment_id' => $payment->id,
        'package_id' => $package->id,
        'amount' => $package->price
    ], $tenantId);
    
    return $response;
}

public function handleCallback(array $callbackData): void
{
    $payment = Payment::where('transaction_id', $callbackData['transaction_id'])
        ->firstOrFail();
    
    // Validate payment belongs to tenant
    $this->validatePayment($payment, $payment->tenant_id);
    
    // Process callback
    $payment->update([
        'status' => $callbackData['status'],
        'mpesa_receipt' => $callbackData['receipt'] ?? null
    ]);
    
    $this->logTenantOperation('payment_callback', [
        'payment_id' => $payment->id,
        'status' => $callbackData['status']
    ], $payment->tenant_id);
}
```

---

### **3. RadiusService.php**

Add this to the class:

```php
public function authenticate(string $username, string $password, string $nasIp): array
{
    // Find router by NAS IP
    $router = Router::where('ip_address', $nasIp)->firstOrFail();
    $tenantId = $router->tenant_id;
    
    // Find user in SAME tenant as router
    $user = HotspotUser::where('username', $username)
        ->where('tenant_id', $tenantId)
        ->first();
    
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }
    
    // Validate user belongs to router's tenant
    $this->validateHotspotUser($user, $tenantId);
    
    // Verify password
    if (!password_verify($password, $user->password)) {
        return ['success' => false, 'message' => 'Invalid password'];
    }
    
    // Check subscription
    if (!$user->has_active_subscription) {
        return ['success' => false, 'message' => 'No active subscription'];
    }
    
    $this->logTenantOperation('radius_auth', [
        'username' => $username,
        'nas_ip' => $nasIp
    ], $tenantId);
    
    return ['success' => true, 'user' => $user];
}
```

---

### **4. BaseMikroTikService.php**

Add this to the class:

```php
protected function connectToRouter(Router $router): Client
{
    $tenantId = $this->getTenantId();
    
    // Validate router belongs to tenant
    $this->validateRouter($router, $tenantId);
    
    // Connect to router
    $client = new Client([
        'host' => $router->ip_address,
        'user' => $router->username,
        'pass' => decrypt($router->password),
    ]);
    
    $this->logTenantOperation('mikrotik_connect', [
        'router_id' => $router->id,
        'router_name' => $router->name
    ], $tenantId);
    
    return $client;
}
```

---

### **5. RADIUSServiceController.php**

Add this to the class:

```php
public function authorize(string $username, string $nasIp): array
{
    $router = Router::where('ip_address', $nasIp)->firstOrFail();
    $user = User::where('username', $username)
        ->where('tenant_id', $router->tenant_id)
        ->firstOrFail();
    
    $this->validateTenantOwnership($router->tenant_id, $user, $router);
    
    return ['authorized' => true];
}
```

---

## 🟡 **PHASE 2: HIGH PRIORITY SERVICES**

### **6. RouterServiceManager.php**

```php
public function createService(Router $router, array $serviceData): RouterService
{
    $tenantId = $this->getTenantId();
    $this->validateRouter($router, $tenantId);
    
    $service = RouterService::create([
        'tenant_id' => $tenantId,
        'router_id' => $router->id,
        'service_type' => $serviceData['type'],
        'service_name' => $serviceData['name'],
        'configuration' => $serviceData['config'],
    ]);
    
    $this->logTenantOperation('create_service', [
        'service_id' => $service->id
    ], $tenantId);
    
    return $service;
}
```

### **7. AccessPointManager.php**

```php
public function registerAccessPoint(Router $router, array $apData): AccessPoint
{
    $tenantId = $this->getTenantId();
    $this->validateRouter($router, $tenantId);
    
    $ap = AccessPoint::create([
        'tenant_id' => $tenantId,
        'router_id' => $router->id,
        'name' => $apData['name'],
        'mac_address' => $apData['mac'],
        'ip_address' => $apData['ip'],
    ]);
    
    return $ap;
}
```

### **8. HotspotService.php**

```php
public function createHotspotUser(Router $router, HotspotUser $user): array
{
    $tenantId = $this->getTenantId();
    $this->validateRouter($router, $tenantId);
    $this->validateHotspotUser($user, $tenantId);
    
    $client = $this->connectToRouter($router);
    
    // Create hotspot user on router
    // ... implementation
}
```

### **9. PPPoEService.php**

```php
public function createPPPoEUser(Router $router, User $user, Package $package): array
{
    $tenantId = $this->getTenantId();
    $this->validateRouter($router, $tenantId);
    $this->validateUser($user, $tenantId);
    $this->validatePackage($package, $tenantId);
    
    $client = $this->connectToRouter($router);
    
    // Create PPPoE user
    // ... implementation
}
```

---

## 🟢 **PHASE 3: MEDIUM PRIORITY SERVICES**

### **10. MetricsService.php**

```php
public function getTenantMetrics(): array
{
    $tenantId = $this->getTenantId();
    
    return [
        'users' => User::where('tenant_id', $tenantId)->count(),
        'routers' => Router::where('tenant_id', $tenantId)->count(),
        'revenue' => Payment::where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->sum('amount'),
        'active_sessions' => $this->getActiveSessions($tenantId),
    ];
}
```

### **11. WhatsAppService.php**

```php
public function sendMessage(User $user, string $message): bool
{
    $tenantId = $this->getTenantId();
    $this->validateUser($user, $tenantId);
    
    // Send WhatsApp message
    // ... implementation
    
    $this->logTenantOperation('whatsapp_sent', [
        'user_id' => $user->id
    ], $tenantId);
    
    return true;
}
```

---

**Use these examples to update all services!**
