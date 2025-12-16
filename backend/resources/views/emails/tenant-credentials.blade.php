<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your WifiCore Account Credentials</title>
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
        .credentials-box {
            background: #f0f9ff;
            border: 2px solid #2563eb;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }
        .credential-item {
            background: #ffffff;
            padding: 12px;
            margin: 10px 0;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 16px;
        }
        .button {
            display: inline-block;
            padding: 14px 28px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 20px 0;
        }
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
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
            <div class="logo">🎉 WifiCore</div>
            <h1 style="color: #1f2937; margin: 0;">Welcome to WifiCore!</h1>
        </div>

        <p>Congratulations! Your WifiCore account has been successfully created.</p>

        <p>Your workspace for <strong>{{ $tenant->name }}</strong> is now ready. Below are your login credentials:</p>

        <div class="credentials-box">
            <h3 style="margin-top: 0; color: #2563eb;">🔐 Your Login Credentials</h3>
            
            <div style="margin-bottom: 8px;">
                <strong>Username:</strong>
                <div class="credential-item">{{ $username }}</div>
            </div>
            
            <div>
                <strong>Password:</strong>
                <div class="credential-item">{{ $password }}</div>
            </div>
        </div>

        <div class="warning-box">
            <strong>⚠️ Important Security Notice:</strong>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li>Please save these credentials in a secure location</li>
                <li>Change your password after your first login</li>
                <li>Never share your credentials with anyone</li>
                <li>This is the only time we'll send your password via email</li>
            </ul>
        </div>

        <div style="text-align: center;">
            <a href="{{ $loginUrl }}" class="button">Login to Your Account</a>
        </div>

        <div class="info-box">
            <strong>🚀 Getting Started:</strong>
            <ol style="margin: 10px 0; padding-left: 20px;">
                <li>Click the login button above</li>
                <li>Enter your username and password</li>
                <li>Complete your profile setup</li>
                <li>Configure your first hotspot</li>
                <li>Start managing your network!</li>
            </ol>
        </div>

        <p><strong>Your Account Details:</strong></p>
        <ul style="list-style: none; padding: 0;">
            <li>🏢 <strong>Company:</strong> {{ $tenant->name }}</li>
            <li>🔗 <strong>Subdomain:</strong> {{ $tenant->slug }}</li>
            <li>📧 <strong>Email:</strong> {{ $registration->tenant_email }}</li>
            <li>🎁 <strong>Trial Period:</strong> 30 days (No credit card required)</li>
        </ul>

        <p><strong>Need Help?</strong></p>
        <p>Check out our documentation or contact our support team if you have any questions.</p>

        <div class="footer">
            <p>&copy; {{ date('Y') }} WifiCore by TraidNet Solutions. All rights reserved.</p>
            <p>Hotspot Management System</p>
        </div>
    </div>
</body>
</html>
