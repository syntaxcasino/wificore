<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email - WifiCore</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background-color: #f3f4f6;
            padding: 20px;
        }
        
        .email-wrapper {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
        }
        
        .email-header {
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #06b6d4 100%);
            padding: 40px 30px;
            text-align: center;
        }
        
        .logo-container {
            display: inline-block;
            background-color: rgba(255, 255, 255, 0.2);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .logo {
            font-size: 32px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.5px;
        }
        
        .header-title {
            color: #ffffff;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .header-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }
        
        .email-body {
            padding: 40px 30px;
        }
        
        .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
        }
        
        .content-text {
            font-size: 15px;
            color: #4b5563;
            margin-bottom: 16px;
            line-height: 1.7;
        }
        
        .cta-container {
            text-align: center;
            margin: 35px 0;
        }
        
        .cta-button {
            display: inline-block;
            padding: 16px 40px;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            transition: all 0.3s ease;
        }
        
        .cta-button:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
            transform: translateY(-2px);
        }
        
        .info-box {
            background-color: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 20px;
            margin: 25px 0;
            border-radius: 6px;
        }
        
        .info-box-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 12px;
        }
        
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .info-list li {
            font-size: 14px;
            color: #1e40af;
            margin-bottom: 8px;
            padding-left: 24px;
            position: relative;
        }
        
        .info-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            font-weight: 700;
            color: #3b82f6;
        }
        
        .company-details {
            background-color: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
        }
        
        .company-details-title {
            font-size: 15px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            font-size: 14px;
            color: #6b7280;
        }
        
        .detail-icon {
            margin-right: 10px;
            font-size: 16px;
        }
        
        .detail-label {
            font-weight: 600;
            color: #374151;
            margin-right: 6px;
        }
        
        .divider {
            height: 1px;
            background-color: #e5e7eb;
            margin: 30px 0;
        }
        
        .security-notice {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 25px 0;
            border-radius: 6px;
        }
        
        .security-notice-text {
            font-size: 13px;
            color: #92400e;
            line-height: 1.6;
        }
        
        .footer-text {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 20px;
            line-height: 1.6;
        }
        
        .email-footer {
            background-color: #f9fafb;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        
        .footer-logo {
            font-size: 18px;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 8px;
        }
        
        .footer-tagline {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 15px;
        }
        
        .footer-links {
            margin: 15px 0;
        }
        
        .footer-link {
            color: #2563eb;
            text-decoration: none;
            font-size: 13px;
            margin: 0 10px;
        }
        
        .footer-link:hover {
            text-decoration: underline;
        }
        
        .copyright {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 15px;
        }
        
        .social-links {
            margin: 15px 0;
        }
        
        .social-link {
            display: inline-block;
            margin: 0 8px;
            color: #6b7280;
            text-decoration: none;
        }
        
        @media only screen and (max-width: 600px) {
            .email-header,
            .email-body,
            .email-footer {
                padding: 25px 20px;
            }
            
            .header-title {
                font-size: 20px;
            }
            
            .cta-button {
                padding: 14px 30px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <!-- Header -->
        <div class="email-header">
            <div class="logo-container">
                <div class="logo">🌐 WifiCore</div>
            </div>
            <h1 class="header-title">Verify Your Email Address</h1>
            <p class="header-subtitle">Complete your registration to get started</p>
        </div>
        
        <!-- Body -->
        <div class="email-body">
            <p class="greeting">Hello {{ $registration->tenant_name }},</p>
            
            <p class="content-text">
                Thank you for choosing WifiCore! We're excited to have you on board. To complete your registration and activate your account, please verify your email address.
            </p>
            
            <!-- CTA Button -->
            <div class="cta-container">
                <a href="{{ $verificationUrl }}" class="cta-button">
                    Verify Email Address
                </a>
            </div>
            
            <!-- What Happens Next -->
            <div class="info-box">
                <div class="info-box-title">What happens next?</div>
                <ul class="info-list">
                    <li>Click the verification button above</li>
                    <li>We'll create your dedicated tenant workspace</li>
                    <li>Your database schema will be provisioned</li>
                    <li>Login credentials will be sent to your email</li>
                    <li>You can start managing your hotspot immediately</li>
                </ul>
            </div>
            
            <!-- Company Details -->
            <div class="company-details">
                <div class="company-details-title">Your Registration Details</div>
                <div class="detail-item">
                    <span class="detail-icon">🏢</span>
                    <span class="detail-label">Company:</span>
                    <span>{{ $registration->tenant_name }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-icon">🔗</span>
                    <span class="detail-label">Subdomain:</span>
                    <span>{{ $registration->tenant_slug }}.wificore.traidsolutions.com</span>
                </div>
                @if($registration->tenant_email)
                <div class="detail-item">
                    <span class="detail-icon">📧</span>
                    <span class="detail-label">Email:</span>
                    <span>{{ $registration->tenant_email }}</span>
                </div>
                @endif
                @if($registration->tenant_phone)
                <div class="detail-item">
                    <span class="detail-icon">📱</span>
                    <span class="detail-label">Phone:</span>
                    <span>{{ $registration->tenant_phone }}</span>
                </div>
                @endif
            </div>
            
            <div class="divider"></div>
            
            <!-- Security Notice -->
            <div class="security-notice">
                <p class="security-notice-text">
                    <strong>Security Notice:</strong> This verification link will expire in 24 hours. If you didn't create this account, you can safely ignore this email.
                </p>
            </div>
            
            <!-- Alternative Link -->
            <p class="footer-text">
                If the button above doesn't work, copy and paste this link into your browser:<br>
                <a href="{{ $verificationUrl }}" style="color: #2563eb; word-break: break-all;">{{ $verificationUrl }}</a>
            </p>
            
            <p class="footer-text">
                Need help? Contact our support team at <a href="mailto:support@wificore.traidsolutions.com" style="color: #2563eb;">support@wificore.traidsolutions.com</a>
            </p>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            <div class="footer-logo">WifiCore</div>
            <div class="footer-tagline">Professional Hotspot Management System</div>
            
            <div class="footer-links">
                <a href="https://wificore.traidsolutions.com" class="footer-link">Website</a>
                <a href="https://wificore.traidsolutions.com/docs" class="footer-link">Documentation</a>
                <a href="https://wificore.traidsolutions.com/support" class="footer-link">Support</a>
            </div>
            
            <div class="copyright">
                &copy; {{ date('Y') }} WifiCore by TraidNet Solutions. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>
