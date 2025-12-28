<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>Your Account Credentials</title>
    <style type="text/css" rel="stylesheet" media="all">
        /* Base ------------------------------ */
        *:not(br):not(tr):not(html) {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            box-sizing: border-box;
        }
        body {
            width: 100% !important;
            height: 100%;
            margin: 0;
            line-height: 1.4;
            background-color: #F4F7FA;
            color: #51545E;
            -webkit-text-size-adjust: none;
        }
        p, ul, ol, blockquote {
            line-height: 1.4;
            text-align: left;
        }
        a {
            color: #3869D4;
        }
        /* Layout ------------------------------ */
        .email-wrapper {
            width: 100%;
            margin: 0;
            padding: 0;
            background-color: #F4F7FA;
        }
        .email-content {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        /* Masthead ----------------------- */
        .email-masthead {
            padding: 25px 0;
            text-align: center;
        }
        .email-masthead_name {
            font-size: 24px;
            font-weight: bold;
            color: #333333;
            text-decoration: none;
            text-shadow: 0 1px 0 white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        /* Body ------------------------------ */
        .email-body {
            width: 100%;
            margin: 0;
            padding: 0;
            border-top: 1px solid #EAEAEC;
            border-bottom: 1px solid #EAEAEC;
            background-color: #FFFFFF;
        }
        .email-body_inner {
            width: 100%;
            margin: 0 auto;
            padding: 0;
        }
        .email-footer {
            width: 100%;
            margin: 0 auto;
            padding: 57px;
            text-align: center;
        }
        .email-footer p {
            color: #839197;
        }
        .body-action {
            width: 100%;
            margin: 30px auto;
            padding: 0;
            text-align: center;
        }
        .body-sub {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #EAEAEC;
        }
        .content-cell {
            padding: 35px;
        }
        /* Type ------------------------------ */
        h1 {
            margin-top: 0;
            color: #333333;
            font-size: 24px;
            font-weight: bold;
            text-align: left;
        }
        p {
            margin-top: 0;
            color: #51545E;
            font-size: 16px;
            line-height: 1.625;
        }
        .sub {
            font-size: 12px;
        }
        /* Buttons ------------------------------ */
        .button {
            display: inline-block;
            width: 200px;
            background-color: #2563EB;
            border-radius: 8px;
            color: #ffffff;
            font-size: 16px;
            font-weight: 600;
            line-height: 45px;
            text-align: center;
            text-decoration: none;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .button--green { background-color: #22BC66; }
        /* Credentials Box ------------------------------ */
        .credentials-box {
            background-color: #EFF6FF;
            border: 2px solid #2563EB;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }
        .credentials-header {
            color: #1E40AF;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 15px;
            border-bottom: 1px solid #BFDBFE;
            padding-bottom: 10px;
        }
        .credential-group {
            margin-bottom: 15px;
        }
        .credential-group:last-child {
            margin-bottom: 0;
        }
        .credential-label {
            display: block;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748B;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .credential-value {
            background-color: #FFFFFF;
            border: 1px solid #CBD5E1;
            padding: 10px 12px;
            border-radius: 6px;
            font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
            font-size: 16px;
            color: #1E293B;
            letter-spacing: 0.5px;
        }
        /* Security Box ------------------------------ */
        .security-box {
            background-color: #FFFBEB;
            border-left: 4px solid #F59E0B;
            padding: 15px;
            border-radius: 4px;
            margin: 25px 0;
        }
        .security-title {
            color: #92400E;
            font-weight: 700;
            font-size: 14px;
            display: block;
            margin-bottom: 5px;
        }
        .security-list {
            margin: 0;
            padding-left: 20px;
            color: #92400E;
            font-size: 13px;
        }
        .security-list li {
            margin-bottom: 3px;
        }
        
        /* Steps Box ------------------------------ */
        .steps-box {
            margin-top: 30px;
            border-top: 1px dashed #CBD5E1;
            padding-top: 25px;
        }
        .step-item {
            display: flex;
            margin-bottom: 15px;
            align-items: flex-start;
        }
        .step-number {
            background-color: #E2E8F0;
            color: #475569;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            text-align: center;
            line-height: 24px;
            font-size: 12px;
            font-weight: bold;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .step-text {
            font-size: 14px;
            color: #475569;
        }

        /* Utilities ------------------------------ */
        .align-center { text-align: center; }
        
        @media only screen and (max-width: 600px) {
            .email-body_inner, .email-footer { width: 100% !important; }
        }
    </style>
</head>
<body>
    <table class="email-wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table class="email-content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                    <!-- Logo -->
                    <tr>
                        <td class="email-masthead">
                            <a href="#" class="email-masthead_name">
                                <span style="font-size: 28px;">🎉</span>
                                <span style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;">WifiCore</span>
                            </a>
                        </td>
                    </tr>
                    <!-- Email Body -->
                    <tr>
                        <td class="email-body" width="100%" cellpadding="0" cellspacing="0">
                            <table class="email-body_inner" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td class="content-cell">
                                        <h1>Welcome Aboard!</h1>
                                        <p>Congratulations! Your WifiCore account has been successfully created and your workspace is ready.</p>
                                        <p>You can now access your dashboard to manage your network, hotspots, and users.</p>
                                        
                                        <!-- Credentials -->
                                        <div class="credentials-box">
                                            <div class="credentials-header">🔐 Your Login Credentials</div>
                                            
                                            <div class="credential-group">
                                                <span class="credential-label">Username</span>
                                                <div class="credential-value">{{ $username }}</div>
                                            </div>
                                            
                                            <div class="credential-group">
                                                <span class="credential-label">Password</span>
                                                <div class="credential-value">{{ $password }}</div>
                                            </div>

                                            <div class="credential-group">
                                                <span class="credential-label">Login URL</span>
                                                <div class="credential-value" style="font-size: 13px;">{{ $loginUrl }}</div>
                                            </div>
                                        </div>

                                        <!-- Action Button -->
                                        <table class="body-action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                            <tr>
                                                <td align="center">
                                                    <table width="100%" border="0" cellspacing="0" cellpadding="0" role="presentation">
                                                        <tr>
                                                            <td align="center">
                                                                <a href="{{ $loginUrl }}" class="button button--green" target="_blank">Login to Dashboard</a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <div class="security-box">
                                            <span class="security-title">⚠️ Important Security Notice</span>
                                            <ul class="security-list">
                                                <li>Please save these credentials in a secure password manager.</li>
                                                <li>You will be prompted to change your password upon first login.</li>
                                                <li>This is the only time we will email you this password.</li>
                                            </ul>
                                        </div>

                                        <div class="steps-box">
                                            <p style="font-weight: 600; font-size: 14px; margin-bottom: 15px;">🚀 Getting Started:</p>
                                            <div class="step-item">
                                                <div class="step-number">1</div>
                                                <div class="step-text">Log in to your dashboard using the button above.</div>
                                            </div>
                                            <div class="step-item">
                                                <div class="step-number">2</div>
                                                <div class="step-text">Complete your organization profile setup.</div>
                                            </div>
                                            <div class="step-item">
                                                <div class="step-number">3</div>
                                                <div class="step-text">Connect your Mikrotik router or configure your first hotspot.</div>
                                            </div>
                                        </div>

                                        <div class="body-sub">
                                            <p class="sub">
                                                <strong>Company:</strong> {{ $tenant->name }}<br>
                                                <strong>Email:</strong> {{ $registration->tenant_email }}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Email Footer -->
                    <tr>
                        <td>
                            <table class="email-footer" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td class="content-cell" align="center">
                                        <p class="sub align-center">
                                            &copy; {{ date('Y') }} WifiCore by TraidNet Solutions.<br>
                                            Need help? Contact support.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
