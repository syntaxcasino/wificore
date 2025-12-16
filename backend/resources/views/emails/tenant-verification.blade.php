<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your WifiCore Account</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 10px;
        }
        .button {
            display: inline-block;
            padding: 14px 28px;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 20px 0;
        }
        .info-box {
            background: #f3f4f6;
            border-left: 4px solid #2563eb;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🌐 WifiCore</div>
            <h1 style="color: #1f2937; margin: 0;">Verify Your Email Address</h1>
        </div>

        <p>Hello,</p>

        <p>Thank you for registering <strong>{{ $registration->tenant_name }}</strong> with WifiCore!</p>

        <p>To complete your registration and activate your account, please verify your email address by clicking the button below:</p>

        <div style="text-align: center;">
            <a href="{{ $verificationUrl }}" class="button">Verify Email Address</a>
        </div>

        <div class="info-box">
            <strong>What happens next?</strong>
            <ol style="margin: 10px 0; padding-left: 20px;">
                <li>Click the verification button above</li>
                <li>We'll create your tenant workspace and database</li>
                <li>Your login credentials will be sent to this email</li>
                <li>You can start using WifiCore immediately!</li>
            </ol>
        </div>

        <p><strong>Your Company Details:</strong></p>
        <ul style="list-style: none; padding: 0;">
            <li>📌 <strong>Company Name:</strong> {{ $registration->tenant_name }}</li>
            <li>🔗 <strong>Subdomain:</strong> {{ $registration->tenant_slug }}</li>
            @if($registration->tenant_email)
            <li>📧 <strong>Email:</strong> {{ $registration->tenant_email }}</li>
            @endif
        </ul>

        <p style="color: #6b7280; font-size: 14px; margin-top: 30px;">
            If you didn't create this account, you can safely ignore this email.
        </p>

        <p style="color: #6b7280; font-size: 14px;">
            This verification link will expire in 24 hours.
        </p>

        <div class="footer">
            <p>&copy; {{ date('Y') }} WifiCore by TraidNet Solutions. All rights reserved.</p>
            <p>Hotspot Management System</p>
        </div>
    </div>
</body>
</html>
