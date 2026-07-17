<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $data['subject'] ?? 'LeadScraper360' }}</title>
    <!-- Google Fonts for premium typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;850&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #0b0f0b;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
            width: 100% !important;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        .wrapper {
            background-color: #0b0f0b;
            padding: 40px 15px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #111612;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.7);
            border: 1px solid #1f2921;
        }
        /* Top brand accent strip */
        .top-accent {
            height: 6px;
            background: linear-gradient(90deg, #a3e635 0%, #65a30d 100%);
        }
        .header {
            background-color: #111612;
            padding: 35px 30px 25px 30px;
            text-align: center;
        }
        .logo-text {
            font-size: 22px;
            font-weight: 850;
            color: #ffffff;
            letter-spacing: -0.5px;
            text-decoration: none;
            display: inline-block;
        }
        .logo-accent {
            color: #a3e635;
        }
        .divider {
            height: 1px;
            background-color: #1f2921;
            margin: 0 35px;
        }
        .common-section {
            padding: 40px 40px 45px 40px;
        }
        /* Typography Styling */
        .greeting {
            color: #ffffff;
            font-size: 22px;
            font-weight: 800;
            line-height: 1.4;
            margin-bottom: 18px;
            letter-spacing: -0.2px;
        }
        .content {
            color: #94a3b8;
            font-size: 15px;
            line-height: 1.7;
            margin-bottom: 24px;
            font-weight: 500;
        }
        .btn-wrapper {
            text-align: center;
            margin: 35px 0;
        }
        .btn {
            background: linear-gradient(135deg, #a3e635 0%, #65a30d 100%);
            color: #000000 !important;
            padding: 15px 36px;
            font-size: 15px;
            font-weight: 800;
            text-decoration: none;
            border-radius: 12px;
            display: inline-block;
            box-shadow: 0 10px 20px -5px rgba(163, 230, 53, 0.25);
            letter-spacing: 0.2px;
        }
        .footer {
            background-color: #0d110e;
            padding: 30px 40px;
            text-align: center;
            border-top: 1px solid #1f2921;
        }
        .footer-text {
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
            font-weight: 500;
        }
        .footer-text a {
            color: #a3e635;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <table role="presentation" class="wrapper">
        <tr>
            <td align="center">
                <table role="presentation" class="container">
                    <!-- Top Accent Line -->
                    <tr>
                        <td class="top-accent"></td>
                    </tr>
                    
                    <!-- Header -->
                    <tr>
                        <td class="header">
                            <a href="https://leadscraper360.com" target="_blank" class="logo-text">
                                LeadScraper<span class="logo-accent">360</span>
                            </a>
                        </td>
                    </tr>
                    
                    <!-- Divider Line -->
                    <tr>
                        <td class="divider"></td>
                    </tr>
                    
                    <!-- Content -->
                    @yield('content')
                    
                    <!-- Footer -->
                    <tr>
                        <td class="footer">
                            <div class="footer-text">
                                &copy; {{ date('Y') }} LeadScraper360. All rights reserved.
                            </div>
                            <div class="footer-text" style="margin-top: 6px; font-size: 11px; color: #475569;">
                                If you did not register for a LeadScraper360 account, please ignore this email or write to us at <a href="mailto:support@leadscraper360.com">support@leadscraper360.com</a>.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
