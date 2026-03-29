# Developer Security Guidelines

**Priority:** P3 (Low Priority)  
**Status:** Active  
**Last Updated:** January 1, 2026

---

## Overview

This document provides security guidelines for developers working on the WiFiCore application. Following these guidelines helps maintain the security posture of the application.

---

## 1. Authentication & Authorization

### Always Validate User Permissions

```php
// ❌ BAD - No permission check
public function update(Request $request, $id)
{
    $router = Router::findOrFail($id);
    $router->update($request->all());
}

// ✅ GOOD - Validate tenant ownership
use App\Traits\ValidatesTenantOwnership;

public function update(Request $request, $id)
{
    $router = Router::findOrFail($id);
    
    if ($error = $this->validateTenantOwnership($router)) {
        return $error;
    }
    
    $router->update($request->validated());
}
```

### Use Form Requests for Validation

```php
// ❌ BAD - Inline validation
public function store(Request $request)
{
    $data = $request->all();
    Router::create($data);
}

// ✅ GOOD - Form Request with validation
public function store(RouterProvisionRequest $request)
{
    Router::create($request->validated());
}
```

---

## 2. Input Validation & Sanitization

### Always Validate User Input

```php
// ❌ BAD - No validation
$username = $request->input('username');

// ✅ GOOD - Validated and sanitized
$username = strip_tags(trim($request->validated()['username']));
```

### Use Appropriate Validation Rules

```php
'email' => ['required', 'email', 'max:255'],
'ip_address' => ['required', 'ip'],
'port' => ['required', 'integer', 'min:1', 'max:65535'],
'mac_address' => ['required', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
```

---

## 3. Database Security

### Use Query Builder or Eloquent

```php
// ❌ BAD - Raw SQL with user input
DB::select("SELECT * FROM users WHERE username = '{$username}'");

// ✅ GOOD - Parameterized query
DB::table('users')->where('username', $username)->get();
```

### Protect Against Mass Assignment

```php
// Define $fillable or $guarded in models
protected $fillable = ['name', 'email', 'role'];

// Or use $guarded
protected $guarded = ['id', 'created_at', 'updated_at'];
```

### Use Transactions for Critical Operations

```php
DB::transaction(function () use ($data) {
    $user = User::create($data);
    $user->profile()->create($profileData);
    $user->assignRole('admin');
});
```

---

## 4. Tenant Isolation

### Always Use TenantAwareJob for Background Jobs

```php
use App\Traits\TenantAwareJob;

class ProcessPaymentJob implements ShouldQueue
{
    use TenantAwareJob;
    
    public function __construct(
        public string $paymentId,
        public string $tenantId
    ) {}
    
    public function handle()
    {
        $this->executeInTenantContext(function() {
            // Your logic here
        });
    }
}
```

### Validate Tenant Ownership

```php
// For public schema tables with tenant_id
$user = User::findOrFail($id);
if ($user->tenant_id !== auth()->user()->tenant_id) {
    abort(403, 'Unauthorized access');
}

// For tenant schema tables (automatically scoped)
$router = Router::findOrFail($id); // Already scoped by TenantScope
```

---

## 5. Password & Secrets Management

### Never Hardcode Secrets

```php
// ❌ BAD
$apiKey = 'sk_live_abc123';

// ✅ GOOD
$apiKey = config('services.payment.api_key');
```

### Use Strong Password Hashing

```php
// ✅ Laravel uses bcrypt by default
$user->password = Hash::make($request->password);

// Verify password
if (Hash::check($request->password, $user->password)) {
    // Password is correct
}
```

### Encrypt Sensitive Data at Rest

```php
use App\Traits\EncryptsAttributes;

class Router extends Model
{
    use EncryptsAttributes;
    
    protected $encrypted = ['password', 'api_key'];
}
```

---

## 6. API Security

### Always Use Rate Limiting

```php
Route::middleware(['throttle:60,1'])->group(function () {
    // Your routes
});
```

### Validate API Requests

```php
// Use Form Requests for all API endpoints
public function store(CreateRouterRequest $request)
{
    // Request is already validated
}
```

### Return Consistent Error Responses

```php
return response()->json([
    'success' => false,
    'message' => 'Validation failed',
    'error_code' => 'VALIDATION_ERROR',
    'errors' => $validator->errors()
], 422);
```

---

## 7. Logging & Monitoring

### Log Security Events

```php
use App\Services\AuditLogService;

// Log authentication
AuditLogService::logAuthentication('login_success', $user->id);

// Log security events
AuditLogService::logSecurity('idor_attempt_blocked', $user->id, [
    'resource_id' => $resourceId,
    'attempted_tenant_id' => $resource->tenant_id,
]);
```

### Never Log Sensitive Data

```php
// ❌ BAD
Log::info('User login', ['password' => $password]);

// ✅ GOOD
Log::info('User login', ['user_id' => $user->id]);
```

---

## 8. File Upload Security

### Validate File Types

```php
'file' => [
    'required',
    'file',
    'max:10240', // 10MB
    'mimes:pdf,jpg,png,doc,docx',
],
```

### Store Files Securely

```php
// Store outside public directory
$path = $request->file('document')->store('documents', 'private');

// Generate secure filename
$filename = Str::uuid() . '.' . $file->extension();
```

---

## 9. Session Security

### Regenerate Session on Authentication

```php
// Already handled by RegenerateSession middleware
// But if manually managing sessions:
$request->session()->regenerate();
```

### Use Secure Cookies

```env
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

---

## 10. Error Handling

### Don't Expose Sensitive Information

```php
// ❌ BAD - Exposes internal details
catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()]);
}

// ✅ GOOD - Generic message in production
catch (\Exception $e) {
    Log::error('Operation failed', ['exception' => $e]);
    return response()->json([
        'error' => config('app.debug') ? $e->getMessage() : 'Operation failed'
    ]);
}
```

---

## 11. Dependency Management

### Keep Dependencies Updated

```bash
# Check for vulnerabilities
composer audit
npm audit

# Update dependencies
composer update
npm update
```

### Review New Dependencies

- Check package reputation
- Review security advisories
- Verify package maintainer
- Check for known vulnerabilities

---

## 12. Code Review Checklist

Before submitting code for review:

- [ ] All user input is validated
- [ ] No SQL injection vulnerabilities
- [ ] No XSS vulnerabilities
- [ ] Tenant isolation maintained
- [ ] Sensitive data encrypted
- [ ] Proper error handling
- [ ] Security events logged
- [ ] Rate limiting applied
- [ ] Tests written
- [ ] No hardcoded secrets

---

## 13. Testing Security

### Write Security Tests

```php
/** @test */
public function it_prevents_cross_tenant_access()
{
    $tenant1Router = Router::factory()->create(['tenant_id' => 'tenant-1']);
    $tenant2User = User::factory()->create(['tenant_id' => 'tenant-2']);
    
    $this->actingAs($tenant2User)
        ->get("/api/routers/{$tenant1Router->id}")
        ->assertStatus(403);
}
```

---

## 14. Common Vulnerabilities to Avoid

### SQL Injection

```php
// ❌ NEVER do this
DB::raw("WHERE id = {$request->id}");

// ✅ Use parameterized queries
DB::table('users')->where('id', $request->id)->get();
```

### XSS (Cross-Site Scripting)

```php
// ❌ Don't trust user input in views
{!! $userInput !!}

// ✅ Escape output
{{ $userInput }}
```

### CSRF

```php
// ✅ Laravel handles this automatically
// Just ensure forms include @csrf token
```

### IDOR (Insecure Direct Object Reference)

```php
// ❌ BAD
$router = Router::findOrFail($id);

// ✅ GOOD
$router = Router::where('tenant_id', auth()->user()->tenant_id)
    ->findOrFail($id);
```

---

## 15. Secure Coding Patterns

### Use Type Hints

```php
public function processPayment(string $paymentId, float $amount): bool
{
    // Type safety
}
```

### Validate Before Processing

```php
public function handle(Request $request)
{
    // Validate first
    $validated = $request->validate([...]);
    
    // Then process
    $this->processData($validated);
}
```

### Fail Securely

```php
// Default to deny
if (!$this->canAccess($resource)) {
    abort(403);
}

// Proceed only if explicitly allowed
```

---

## 16. Security Resources

### Internal Resources
- `docs/COMPREHENSIVE_SECURITY_AUDIT.md`
- `docs/P1_SECURITY_FIXES.md`
- `docs/P2_SECURITY_FIXES.md`
- `docs/TENANT_ISOLATION_AUDIT.md`

### External Resources
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Laravel Security Best Practices](https://laravel.com/docs/security)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)

---

## 17. Incident Response

If you discover a security vulnerability:

1. **Do NOT commit the vulnerability**
2. Report immediately to security team
3. Document the issue privately
4. Wait for security team guidance
5. Apply fix after review
6. Update security documentation

---

## 18. Security Training

All developers must:
- Complete security training annually
- Review this document quarterly
- Participate in security code reviews
- Stay updated on security advisories

---

**Remember: Security is everyone's responsibility!**

---

**Last Updated:** January 1, 2026  
**Next Review:** April 1, 2026
