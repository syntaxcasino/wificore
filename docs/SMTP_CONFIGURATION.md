# SMTP Email Configuration Guide

This guide explains how to configure email sending using various SMTP providers in the WiFiCore application.

## Current Configuration

Your application is currently configured to use **Brevo (formerly Sendinblue)** SMTP service.

### Active Settings (.env)
```env
MAIL_MAILER=smtp
MAIL_ENCRYPTION=tls
MAIL_HOST=smtp-relay.brevo.com
MAIL_USERNAME=9eb281001@smtp-brevo.com
MAIL_PASSWORD=Dq0pAVZTSdznfKy
MAIL_PORT=587
MAIL_TIMEOUT=30
MAIL_FROM_ADDRESS=noreply@traidsolutions.com
MAIL_FROM_NAME="TraidSolutions (TraidLink)"
```

## Supported SMTP Providers

The application supports the following SMTP providers with easy switching:

### 1. Brevo (Sendinblue) - Currently Active ✓

**Configuration:**
```env
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-brevo-username
MAIL_PASSWORD=your-brevo-smtp-key
```

**How to get credentials:**
1. Sign up at [brevo.com](https://www.brevo.com)
2. Go to Settings → SMTP & API
3. Create SMTP credentials
4. Copy the username and SMTP key

**Limits:** Free tier includes 300 emails/day

---

### 2. SendGrid

**Configuration:**
```env
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=apikey
MAIL_PASSWORD=your-sendgrid-api-key
```

**How to get credentials:**
1. Sign up at [sendgrid.com](https://sendgrid.com)
2. Go to Settings → API Keys
3. Create an API Key with "Mail Send" permissions
4. Username is always `apikey` (literal string)
5. Password is your API key

**Limits:** Free tier includes 100 emails/day

---

### 3. Mailjet

**Configuration:**
```env
MAIL_HOST=in-v3.mailjet.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-mailjet-api-key
MAIL_PASSWORD=your-mailjet-secret-key
```

**How to get credentials:**
1. Sign up at [mailjet.com](https://www.mailjet.com)
2. Go to Account Settings → API Key Management
3. Create or use existing API Key
4. Username is the API Key
5. Password is the Secret Key

**Limits:** Free tier includes 200 emails/day, 6,000/month

---

### 4. SendPulse

**Configuration:**
```env
MAIL_HOST=smtp-pulse.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-sendpulse-email
MAIL_PASSWORD=your-sendpulse-password
```

**How to get credentials:**
1. Sign up at [sendpulse.com](https://sendpulse.com)
2. Go to Settings → SMTP
3. Enable SMTP
4. Use your SendPulse account email as username
5. Generate an SMTP password

**Limits:** Free tier includes 12,000 emails/month

---

### 5. MailerSend

**Configuration:**
```env
MAIL_HOST=smtp.mailersend.net
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=MS_xxxxxx@yourdomain.com
MAIL_PASSWORD=your-mailersend-smtp-password
MAILERSEND_API_KEY=your-api-key
```

**How to get credentials:**
1. Sign up at [mailersend.com](https://www.mailersend.com)
2. Add and verify your domain
3. Go to Settings → SMTP
4. Generate SMTP credentials
5. Username format: `MS_xxxxxx@yourdomain.com`

**Limits:** Free tier includes 12,000 emails/month

---

### 6. SMTP2GO

**Configuration:**
```env
MAIL_HOST=mail.smtp2go.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-smtp2go-username
MAIL_PASSWORD=your-smtp2go-password
```

**How to get credentials:**
1. Sign up at [smtp2go.com](https://www.smtp2go.com)
2. Go to Settings → Users
3. Create a new SMTP user
4. Set username and password

**Limits:** Free tier includes 1,000 emails/month

---

### 7. Maileroo

**Configuration:**
```env
MAIL_HOST=smtp.maileroo.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-maileroo-username
MAIL_PASSWORD=your-maileroo-api-key
```

**How to get credentials:**
1. Sign up at [maileroo.com](https://maileroo.com)
2. Go to Settings → SMTP Settings
3. Create SMTP credentials
4. Copy username and API key

**Limits:** Free tier includes 3,000 emails/month

---

## How to Switch Providers

1. **Update .env file** with the new provider's configuration:
   ```bash
   MAIL_HOST=new-smtp-host.com
   MAIL_USERNAME=new-username
   MAIL_PASSWORD=new-password
   ```

2. **Clear configuration cache** (if in production):
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

3. **Rebuild Docker containers** (since this is a Docker setup):
   ```bash
   docker-compose down
   docker-compose up -d --build
   ```

4. **Test email sending**:
   ```bash
   php artisan tinker
   Mail::raw('Test email', function($msg) {
       $msg->to('test@example.com')->subject('Test');
   });
   ```

## Common Configuration Options

### Standard SMTP Ports
- **Port 587**: TLS encryption (recommended)
- **Port 465**: SSL encryption
- **Port 25**: No encryption (not recommended)

### Encryption Types
- `tls`: Transport Layer Security (recommended)
- `ssl`: Secure Sockets Layer
- `null`: No encryption (not recommended)

### Timeout Settings
- Default: 30 seconds
- Increase if experiencing timeout errors
- Decrease for faster failure detection

## Troubleshooting

### Connection Timeout
```env
MAIL_TIMEOUT=60  # Increase timeout
```

### Authentication Failed
- Verify username and password are correct
- Check if 2FA is enabled (may need app-specific password)
- Ensure SMTP access is enabled in provider settings

### TLS/SSL Errors
```env
MAIL_ENCRYPTION=tls  # Try switching between tls/ssl
```

### Rate Limiting
- Check your provider's sending limits
- Implement queue system for bulk emails
- Consider upgrading to paid tier

## Testing Email Configuration

### Using Artisan Tinker
```bash
php artisan tinker

# Send test email
Mail::raw('This is a test email', function($message) {
    $message->to('recipient@example.com')
            ->subject('Test Email from WiFiCore');
});
```

### Check Mail Logs
```bash
# View Laravel logs
tail -f storage/logs/laravel.log

# Inside Docker container
docker-compose exec backend tail -f storage/logs/laravel.log
```

## Best Practices

1. **Use Environment Variables**: Never hardcode credentials in code
2. **Verify Domain**: Most providers require domain verification
3. **Monitor Limits**: Track your email usage to avoid hitting limits
4. **Queue Emails**: Use Laravel queues for bulk email sending
5. **Handle Failures**: Implement proper error handling and retry logic
6. **Test Regularly**: Verify email functionality after configuration changes

## Security Considerations

1. **Protect .env file**: Never commit to version control
2. **Use strong passwords**: Generate secure SMTP passwords
3. **Enable 2FA**: On your SMTP provider account
4. **Rotate credentials**: Periodically update SMTP passwords
5. **Monitor usage**: Watch for unusual sending patterns

## Support

For provider-specific issues, contact:
- **Brevo**: support@brevo.com
- **SendGrid**: support.sendgrid.com
- **Mailjet**: support@mailjet.com
- **SendPulse**: support@sendpulse.com
- **MailerSend**: support@mailersend.com
- **SMTP2GO**: support@smtp2go.com
- **Maileroo**: support@maileroo.com
