<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>Verify Your Email</title>
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
        a img {
            border: none;
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
        h2 {
            margin-top: 0;
            color: #333333;
            font-size: 16px;
            font-weight: bold;
            text-align: left;
        }
        h3 {
            margin-top: 0;
            color: #333333;
            font-size: 14px;
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
            -webkit-text-size-adjust: none;
            mso-hide: all;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .button--green {
            background-color: #22BC66;
        }
        .button--red {
            background-color: #FF6136;
        }
        .button--blue {
            background-color: #2563EB;
        }
        /* Details Box ------------------------------ */
        .details-box {
            background-color: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }
        .details-item {
            display: flex;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .details-item:last-child {
            margin-bottom: 0;
        }
        .details-label {
            font-weight: 600;
            width: 120px;
            color: #64748B;
        }
        .details-value {
            color: #334155;
            font-weight: 500;
        }
        /* Info Box ------------------------------ */
        .info-box {
            background-color: #EFF6FF;
            border-left: 4px solid #2563EB;
            padding: 15px;
            border-radius: 4px;
            margin: 25px 0;
        }
        .info-title {
            color: #1E40AF;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 8px;
            display: block;
        }
        .info-list {
            margin: 0;
            padding-left: 20px;
            color: #1E40AF;
            font-size: 14px;
        }
        .info-list li {
            margin-bottom: 5px;
        }

        /* Utilities ------------------------------ */
        .align-right { text-align: right; }
        .align-left { text-align: left; }
        .align-center { text-align: center; }
        
        /* Media Queries ------------------------------ */
        @media only screen and (max-width: 600px) {
            .email-body_inner,
            .email-footer {
                width: 100% !important;
            }
        }
        @media only screen and (max-width: 500px) {
            .button {
                width: 100% !important;
            }
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
                                <span style="font-size: 28px;">🌐</span>
                                <span style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;">WifiCore</span>
                            </a>
                        </td>
                    </tr>
                    <!-- Email Body -->
                    <tr>
                        <td class="email-body" width="100%" cellpadding="0" cellspacing="0">
                            <table class="email-body_inner" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                <!-- Body content -->
                                <tr>
                                    <td class="content-cell">
                                        <h1>Verify Your Email Address</h1>
                                        <p>Hello,</p>
                                        <p>Thank you for choosing WifiCore! To complete your registration for <strong>{{ $registration->tenant_name }}</strong> and activate your workspace, please verify your email address.</p>
                                        
                                        <!-- Action Button -->
                                        <table class="body-action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                            <tr>
                                                <td align="center">
                                                    <!-- Border based button -->
                                                    <table width="100%" border="0" cellspacing="0" cellpadding="0" role="presentation">
                                                        <tr>
                                                            <td align="center">
                                                                <a href="{{ $verificationUrl }}" class="button button--blue" target="_blank">Verify Email Address</a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <div class="info-box">
                                            <span class="info-title">What happens next?</span>
                                            <ol class="info-list">
                                                <li>We'll create your secure tenant workspace</li>
                                                <li>Your login credentials will be sent to this email</li>
                                                <li>You can start managing your hotspot network immediately</li>
                                            </ol>
                                        </div>

                                        <div class="details-box">
                                            <div class="details-item">
                                                <div class="details-label">Company:</div>
                                                <div class="details-value">{{ $registration->tenant_name }}</div>
                                            </div>
                                            <div class="details-item">
                                                <div class="details-label">Subdomain:</div>
                                                <div class="details-value">{{ $registration->tenant_slug }}</div>
                                            </div>
                                            @if($registration->tenant_email)
                                            <div class="details-item">
                                                <div class="details-label">Email:</div>
                                                <div class="details-value">{{ $registration->tenant_email }}</div>
                                            </div>
                                            @endif
                                        </div>

                                        <p style="font-size: 13px; color: #94A3B8;">This verification link will expire in 24 hours.</p>
                                        
                                        <div class="body-sub">
                                            <p class="sub">If you did not create an account with WifiCore, please ignore this email or contact support if you have questions.</p>
                                            <p class="sub" style="margin-top: 15px; word-break: break-all;">
                                                Having trouble clicking the button? Copy and paste the URL below into your web browser:
                                                <br>
                                                <a href="{{ $verificationUrl }}" style="color: #3869D4;">{{ $verificationUrl }}</a>
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
                                            All rights reserved.
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
