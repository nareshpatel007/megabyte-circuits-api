<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? ($company_name ?? 'Megabyte Circuit') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 0;
            color: #1f2937;
            -webkit-font-smoothing: antialiased;
        }
        table {
            border-collapse: collapse;
        }
        .email-wrapper {
            width: 100%;
            background-color: #f3f4f6;
            padding: 30px 15px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .email-header {
            background-color: #ffffff;
            padding: 25px 30px 20px 30px;
            text-align: center;
            border-bottom: 2px solid #10b981;
        }
        .logo-img {
            max-width: 180px;
            max-height: 60px;
            height: auto;
            display: inline-block;
        }
        .company-title {
            color: #10b981;
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            text-decoration: none;
        }
        .email-body {
            padding: 30px;
            color: #374151;
            font-size: 15px;
            line-height: 1.6;
        }
        .email-footer {
            background-color: #f9fafb;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            color: #6b7280;
        }
        .footer-links {
            margin-bottom: 12px;
        }
        .footer-links a {
            color: #10b981;
            text-decoration: none;
            margin: 0 8px;
            font-weight: 500;
        }
        .social-links {
            margin: 12px 0;
        }
        .social-links a {
            color: #4b5563;
            text-decoration: none;
            margin: 0 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .copyright {
            margin-top: 12px;
            font-size: 12px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" class="email-wrapper" style="width: 100%; background-color: #f3f4f6; padding: 30px 15px;">
        <tr>
            <td align="center" style="text-align: center;">
                <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" class="email-container" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); text-align: left;">
                    <!-- HEADER -->
                    <tr>
                        <td class="email-header" align="center" style="background-color: #ffffff; padding: 25px 30px 20px 30px; text-align: center; border-bottom: 2px solid #10b981;">
                            @if (!empty($company_logo_url))
                                <img src="{{ $company_logo_url }}" alt="{{ $company_name ?? 'Megabyte Circuit' }}" class="logo-img" style="max-width: 220px; max-height: 70px; height: auto; display: inline-block;" width="220" />
                            @else
                                <h1 class="company-title" style="color: #10b981; font-size: 24px; font-weight: 700; margin: 0;">{{ $company_name ?? 'Megabyte Circuit' }}</h1>
                            @endif
                        </td>
                    </tr>

                    <!-- CONTENT -->
                    <tr>
                        <td class="email-body">
                            {!! $body_content !!}
                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td class="email-footer">
                            <div style="margin-bottom: 10px;">
                                Thank you for choosing <strong>{{ $company_name ?? 'Megabyte Circuit' }}</strong>.
                            </div>

                            @if (!empty($support_email) || !empty($support_phone))
                                <div style="margin-bottom: 10px;">
                                    If you have any questions, contact us at 
                                    @if(!empty($support_email))
                                        <a href="mailto:{{ $support_email }}" style="color: #10b981; text-decoration: none;">{{ $support_email }}</a>
                                    @endif
                                    @if(!empty($support_email) && !empty($support_phone)) | @endif
                                    @if(!empty($support_phone))
                                        <a href="tel:{{ $support_phone }}" style="color: #10b981; text-decoration: none;">{{ $support_phone }}</a>
                                    @endif
                                </div>
                            @endif

                            @if (!empty($company_address))
                                <div style="margin-bottom: 10px; color: #6b7280;">
                                    {{ $company_address }}
                                </div>
                            @endif

                            <div class="footer-links">
                                @if(!empty($company_website))
                                    <a href="{{ $company_website }}" target="_blank">Website</a>
                                @endif
                                <a href="{{ rtrim(config('app.main_url', 'https://megabytecircuit.com'), '/') }}/contact" target="_blank">Contact Us</a>
                                <a href="{{ rtrim(config('app.main_url', 'https://megabytecircuit.com'), '/') }}/privacy-policy" target="_blank">Privacy Policy</a>
                                <a href="{{ rtrim(config('app.main_url', 'https://megabytecircuit.com'), '/') }}/terms-of-service" target="_blank">Terms & Conditions</a>
                            </div>

                            @if(!empty($facebook_url) || !empty($instagram_url) || !empty($linkedin_url) || !empty($twitter_url))
                                <div class="social-links">
                                    @if(!empty($facebook_url))
                                        <a href="{{ $facebook_url }}" target="_blank">Facebook</a>
                                    @endif
                                    @if(!empty($instagram_url))
                                        <a href="{{ $instagram_url }}" target="_blank">Instagram</a>
                                    @endif
                                    @if(!empty($linkedin_url))
                                        <a href="{{ $linkedin_url }}" target="_blank">LinkedIn</a>
                                    @endif
                                    @if(!empty($twitter_url))
                                        <a href="{{ $twitter_url }}" target="_blank">Twitter / X</a>
                                    @endif
                                </div>
                            @endif

                            <div class="copyright">
                                &copy; {{ $current_year ?? date('Y') }} {{ $company_name ?? 'Megabyte Circuit' }}. All rights reserved.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
