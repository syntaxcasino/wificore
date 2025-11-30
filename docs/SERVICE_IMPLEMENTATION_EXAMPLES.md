# Service Implementation Examples - Tenant Awareness

**Complete implementation examples for all service types**

---

## 🔴 **PHASE 1: CRITICAL SERVICES**

### **1. MikrotikSessionService**

```php
<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\HotspotUser;

class MikrotikSessionService extends TenantAwareService
{
    public function createSession(
        string $voucherCode, 
        string $macAddress, 
        string $routerId
    ): array {
        // Get tenant ID from authenticated user or payment context
        $tenantId = $this->getTenantId();
        
        // Validate and get voucher
        $voucher = Voucher::where('code', $voucherCode)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        
        // Validate and get router
        $router = $this->getRouterForTenant($routerId, $tenantId);
        
        // Validate package belongs to tenant
        $this->validatePackage($voucher->package, $tenantId);
        
        // Log operation
        $this->logTenantOperation('create_session', [
            'voucher' => $voucherCode,
            'router' => $routerId,
            'mac' => $macAddress
        ], $tenantId);
        
        // Create session (now safe - all validated)
        return $this->createHotspotUser($voucher, $macAddress, $router);
    }
    
    public function disconnectSession(string $username, string $routerId): bool
    {
        $tenantId = $this->getTenantId();
        
        // Validate router
        $router = $this->getRouterForTenant($routerId, $tenantId);
        
        // Validate user belongs to tenant
        $user = HotspotUser::where('username', $username)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        
        $this->validateHotspotUser($user, $tenantId);
        
        // Disconnect
        return $this->disconnectUser($user, $router);
    }
}
```

---

### **2. UserProvisioningService**

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\Payment;
use App\Models\Package;
use App\Models\Router;

class UserProvisioningService extends TenantAwareService
{
    public function processPayment(Payment $payment): array
    {
        // Get tenant from payment
        $tenantId = $payment->tenant_id;
        
        // Validate payment
        $this->validatePayment($payment, $tenantId);
        
        // Get and validate package
        $package = Package::findOrFail($payment->package_id);
        $this->validatePackage($package, $tenantId);
        
        // Get router for this tenant
        $router = Router::where('tenant_id', $tenantId)
            ->where('status', 'online')
            ->firstOrFail();
        
        $this->validateRouter($router, $tenantId);
        
        // Create or update user
        $user = $this->findOrCreateUser($payment, $tenantId);
        
        // Provision on correct tenant's router
        return $this->provisionUser($user, $package, $router, $tenantId);
    }
    
    private function findOrCreateUser(Payment $payment, string $tenantId): User
    {
        $user = User::where('phone_number', $payment->phone_number)
            ->where('tenant_id', $tenantId)
            ->first();
        
        if (!$user) {
            $user = User::create([
                'tenant_id' => $tenantId,
                'phone_number' => $payment->phone_number,
                'mac_address' => $payment->mac_address,
                // ...
            ]);
        }
        
        return $user;
    }
}
```

---

### **3. SubscriptionManager**

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\Package;
use App\Models\UserSubscription;

class SubscriptionManager extends TenantAwareService
{
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
        
        return $subscription;
    }
}
```

---

### **4. MpesaService**

```php
<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Payment;

class MpesaService extends TenantAwareService
{
    public function initiatePayment(
        Package $package, 
        string $phoneNumber,
        string $macAddress
    ): array {
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
            'mpesa_receipt' => $callbackData['receipt']
        ]);
    }
}
```

---

### **5. RadiusService**

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\Router;
use App\Models\HotspotUser;

class RadiusService extends TenantAwareService
{
    public function authenticate(
        string $username, 
        string $password, 
        string $nasIp
    ): array {
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
}
```

---

## 🟡 **PHASE 2: HIGH PRIORITY SERVICES**

### **6. RouterServiceManager**

```php
<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterService;

class RouterServiceManager extends TenantAwareService
{
    public function createService(Router $router, array $serviceData): RouterService
    {
        $tenantId = $this->getTenantId();
        
        // Validate router
        $this->validateRouter($router, $tenantId);
        
        // Create service
        $service = RouterService::create([
            'tenant_id' => $tenantId,
            'router_id' => $router->id,
            'service_type' => $serviceData['type'],
            'service_name' => $serviceData['name'],
            'configuration' => $serviceData['config'],
        ]);
        
        return $service;
    }
    
    public function updateService(RouterService $service, array $data): RouterService
    {
        $tenantId = $this->getTenantId();
        
        // Validate service belongs to tenant
        if ($service->tenant_id !== $tenantId) {
            throw new \Exception('Service does not belong to this tenant');
        }
        
        $service->update($data);
        return $service;
    }
}
```

---

### **7. AccessPointManager**

```php
<?php

namespace App\Services;

use App\Models\Router;
use App\Models\AccessPoint;

class AccessPointManager extends TenantAwareService
{
    public function registerAccessPoint(Router $router, array $apData): AccessPoint
    {
        $tenantId = $this->getTenantId();
        
        // Validate router
        $this->validateRouter($router, $tenantId);
        
        // Create AP
        $ap = AccessPoint::create([
            'tenant_id' => $tenantId,
            'router_id' => $router->id,
            'name' => $apData['name'],
            'mac_address' => $apData['mac'],
            'ip_address' => $apData['ip'],
        ]);
        
        return $ap;
    }
}
```

---

### **8. BaseMikroTikService**

```php
<?php

namespace App\Services\MikroTik;

use App\Services\TenantAwareService;
use App\Models\Router;
use RouterOS\Client;

class BaseMikroTikService extends TenantAwareService
{
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
        
        return $client;
    }
}
```

---

### **9. HotspotService (extends BaseMikroTikService)**

```php
<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Models\HotspotUser;

class HotspotService extends BaseMikroTikService
{
    public function createHotspotUser(
        Router $router, 
        HotspotUser $user
    ): array {
        $tenantId = $this->getTenantId();
        
        // Validate both belong to tenant
        $this->validateRouter($router, $tenantId);
        $this->validateHotspotUser($user, $tenantId);
        
        // Connect and create user
        $client = $this->connectToRouter($router);
        
        // Create hotspot user on router
        // ...
    }
}
```

---

## 🟢 **PHASE 3: MEDIUM PRIORITY SERVICES**

### **10. MetricsService**

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\Router;
use App\Models\Payment;

class MetricsService extends TenantAwareService
{
    public function getTenantMetrics(): array
    {
        $tenantId = $this->getTenantId();
        
        // All queries automatically filtered by TenantScope
        // But we explicitly validate for clarity
        return [
            'users' => User::where('tenant_id', $tenantId)->count(),
            'routers' => Router::where('tenant_id', $tenantId)->count(),
            'revenue' => Payment::where('tenant_id', $tenantId)
                ->where('status', 'completed')
                ->sum('amount'),
            'active_sessions' => $this->getActiveSessions($tenantId),
        ];
    }
    
    private function getActiveSessions(string $tenantId): int
    {
        return \DB::table('user_sessions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();
    }
}
```

---

### **11. WhatsAppService**

```php
<?php

namespace App\Services;

use App\Models\User;

class WhatsAppService extends TenantAwareService
{
    public function sendMessage(User $user, string $message): bool
    {
        $tenantId = $this->getTenantId();
        
        // Validate user belongs to tenant
        $this->validateUser($user, $tenantId);
        
        // Send WhatsApp message
        // ...
        
        $this->logTenantOperation('whatsapp_sent', [
            'user_id' => $user->id,
            'phone' => $user->phone_number
        ], $tenantId);
        
        return true;
    }
}
```

---

## 🧪 **TESTING TEMPLATE**

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Package;
use App\Services\ServiceName;

class ServiceNameTest extends TestCase
{
    public function test_cannot_access_other_tenant_resources()
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $packageB = Package::factory()->create(['tenant_id' => $tenantB->id]);
        
        $this->actingAs($userA);
        
        $service = new ServiceName();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not belong to this tenant');
        
        $service->someMethod($packageB);
    }
    
    public function test_can_access_own_tenant_resources()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $package = Package::factory()->create(['tenant_id' => $tenant->id]);
        
        $this->actingAs($user);
        
        $service = new ServiceName();
        $result = $service->someMethod($package);
        
        $this->assertNotNull($result);
    }
}
```

---

**Status**: ✅ **IMPLEMENTATION GUIDE COMPLETE**  
**Next**: Apply these patterns to all services  
**Timeline**: 24-48 hours for all services
