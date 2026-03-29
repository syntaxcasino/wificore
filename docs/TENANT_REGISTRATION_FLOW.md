# Tenant Registration Flow - Event-Based Architecture

## Overview
The tenant registration process is fully event-based using WebSockets (Soketi/Pusher) with polling fallback. No page redirects occur after email verification - all state updates happen via real-time events.

## Flow Architecture

### Step 1: Registration Form Submission
**Frontend**: `TenantRegistrationView.vue`
- User fills company details and submits form
- POST `/register/tenant`
- Receives registration token
- Moves to Step 2 (Email Verification)
- Subscribes to WebSocket channel: `tenant-registration.{token}`

**Backend**: `TenantRegistrationController@register`
- Creates `TenantRegistration` record
- Dispatches `SendVerificationEmailJob` to queue
- Returns registration token

### Step 2: Email Verification
**Email Link**: User clicks verification link in email
- Opens in new tab/window: `/register/verify/{token}`
- `VerifyEmailView.vue` handles verification
- **NO REDIRECT** - User stays on verification page with success message

**Backend**: `TenantRegistrationController@verifyEmail`
- Marks email as verified
- **Immediately broadcasts** `TenantEmailVerified` event via WebSocket
- Dispatches `CreateTenantWorkspaceJob` to queue
- Returns JSON success response

**Frontend**: Registration page receives event
- WebSocket listener catches `.email.verified` event
- Automatically moves to Step 3 (Workspace Creation)
- Shows "Email Verified!" message
- No page refresh or redirect needed

### Step 3: Workspace Creation
**Backend**: `CreateTenantWorkspaceJob`
- Creates tenant schema
- Creates tenant record
- Creates admin user
- Creates RADIUS credentials
- Broadcasts events:
  - `workspace.creating`
  - `workspace.created`
  - `credentials.sent`

**Frontend**: Registration page receives events
- Updates UI in real-time based on events
- Shows progress: "Creating workspace..." → "Sending credentials..."
- On `credentials.sent`: Shows completion message
- Auto-redirects to login after 3 seconds

## Event Flow Diagram

```
User Submits Form
    ↓
Registration Created
    ↓
Verification Email Sent
    ↓
[User clicks email link in NEW TAB]
    ↓
Email Verified (Backend)
    ↓
WebSocket Event: email.verified ──→ [Registration Page Updates]
    ↓                                        ↓
Workspace Job Dispatched              Step 2 → Step 3
    ↓                                 "Email Verified!"
Workspace Created
    ↓
WebSocket Event: workspace.created ──→ [Registration Page Updates]
    ↓                                   "Workspace Created!"
Credentials Sent
    ↓
WebSocket Event: credentials.sent ──→ [Registration Page Updates]
                                      "Complete! Redirecting..."
                                            ↓
                                      Auto-redirect to Login
```

## Key Features

### 1. No Page Redirects
- Email verification happens in separate tab/window
- Original registration page stays open and receives events
- Seamless user experience without page reloads

### 2. Real-Time Updates
- WebSocket events provide instant feedback
- Progress indicators update automatically
- User sees exactly what's happening

### 3. Fallback Mechanism
- Polling every 3 seconds if WebSocket fails
- Ensures reliability even with connection issues
- Automatic failover without user intervention

### 4. Session Persistence
- Registration token stored in `sessionStorage`
- Survives page refresh
- Resumes listening to events after refresh

### 5. Error Handling
- WebSocket connection errors handled gracefully
- Failed jobs update registration status
- User sees clear error messages

## WebSocket Channels

### Channel Name
```
tenant-registration.{registration_token}
```

### Events Broadcast

#### 1. `email.verified`
```json
{
  "status": "email_verified",
  "message": "Email verified successfully!",
  "registration": {
    "token": "abc123...",
    "tenant_slug": "company-name",
    "email_verified": true
  }
}
```

#### 2. `workspace.creating`
```json
{
  "status": "creating_workspace",
  "message": "Creating your workspace..."
}
```

#### 3. `workspace.created`
```json
{
  "status": "workspace_created",
  "message": "Workspace created successfully!",
  "tenant": {
    "id": "uuid",
    "name": "Company Name",
    "slug": "company-name"
  }
}
```

#### 4. `credentials.sent`
```json
{
  "status": "credentials_sent",
  "message": "Login credentials sent to your email",
  "credentials_sent": true
}
```

## Frontend Implementation

### WebSocket Subscription
```javascript
// Subscribe to registration channel
echoChannel = Echo.channel(`tenant-registration.${token}`)

// Listen for email verified
echoChannel.listen('.email.verified', (data) => {
  currentStep.value = 3
  stepStatus.value.step2 = 'done'
  stepStatus.value.step3 = 'processing'
  // Update UI
})

// Listen for credentials sent
echoChannel.listen('.credentials.sent', (data) => {
  stepStatus.value.step3 = 'done'
  // Show completion and redirect
})
```

### Polling Fallback
```javascript
// Poll every 3 seconds
setInterval(async () => {
  const response = await axios.get(`/register/status/${token}`)
  if (response.data.credentials_sent) {
    handleCredentialsSent()
  }
}, 3000)
```

## Backend Implementation

### Broadcasting Events
```php
// In TenantRegistrationController
event(new TenantEmailVerified($registration));

// In CreateTenantWorkspaceJob
event(new TenantWorkspaceCreating($registration));
event(new TenantWorkspaceCreated($registration, $tenant));
event(new TenantCredentialsSent($registration));
```

### Event Classes
All events implement `ShouldBroadcast`:
- `TenantEmailVerified`
- `TenantWorkspaceCreating`
- `TenantWorkspaceCreated`
- `TenantCredentialsSent`

## Configuration Requirements

### Environment Variables
```env
# Broadcasting
BROADCAST_DRIVER=pusher
BROADCAST_CONNECTION=pusher

# Pusher/Soketi
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=app-key
PUSHER_APP_SECRET=app-secret
PUSHER_HOST=wificore-soketi
PUSHER_PORT=6071
PUSHER_SCHEME=http

# Queue
QUEUE_CONNECTION=database
```

### Queue Workers
Ensure queue workers are running:
```bash
php artisan queue:work --queue=emails,tenant-management
```

### Soketi Server
Ensure Soketi is running:
```bash
docker-compose ps wificore-soketi
```

## Troubleshooting

### Issue: Events not received
**Check:**
1. Soketi service is running
2. Queue workers are processing jobs
3. Browser console for WebSocket connection
4. Backend logs for event broadcasting

**Debug:**
```bash
# Check Soketi logs
docker-compose logs -f wificore-soketi

# Check queue jobs
docker-compose exec wificore-backend php artisan queue:work --verbose

# Test WebSocket connection
# Open browser console on registration page
Echo.connector.pusher.connection.state // should be "connected"
```

### Issue: Duplicate registration tabs
**Fixed:** Email verification no longer redirects back to registration page. User stays on verification success page while original registration page receives events.

### Issue: Polling not working
**Check:**
1. `/register/status/{token}` endpoint is accessible
2. Registration token is valid
3. Browser console for polling errors

## Testing

### Manual Test Flow
1. Open registration page
2. Fill form and submit
3. Open email in NEW TAB
4. Click verification link
5. Verify email (stays on verification page)
6. Switch back to registration tab
7. Watch automatic progression through steps
8. Verify redirect to login after completion

### Expected Behavior
- ✓ No page redirects during verification
- ✓ Real-time step progression
- ✓ Clear status messages at each step
- ✓ Automatic redirect to login when complete
- ✓ Works even if WebSocket fails (polling fallback)

## Security Considerations

1. **Token Validation**: Registration tokens are single-use and validated on backend
2. **Channel Authorization**: Public channels (no sensitive data broadcast)
3. **Session Storage**: Tokens stored in sessionStorage (cleared on tab close)
4. **HTTPS Required**: WebSocket connections require secure transport in production

## Performance

- **WebSocket**: Instant event delivery (<100ms)
- **Polling Fallback**: 3-second intervals (minimal server load)
- **Queue Jobs**: Async processing (no user waiting)
- **Auto-cleanup**: Session tokens cleared after completion

## Future Enhancements

1. **Private Channels**: Add authentication for WebSocket channels
2. **Progress Percentage**: Show detailed progress during workspace creation
3. **Retry Mechanism**: Allow manual retry of failed steps
4. **Email Templates**: Rich HTML emails with better branding
5. **SMS Verification**: Optional phone verification step
