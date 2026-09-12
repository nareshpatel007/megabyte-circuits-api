<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Contact Inquiry - Megabyte Circuit Systems</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #333333; background-color: #f4f5f7; margin: 0; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
        
        <!-- Header -->
        <div style="background-color: #007328; color: #ffffff; padding: 20px; text-align: center;">
            <h2 style="margin: 0; font-size: 20px; font-weight: bold;">Megabyte Circuit Systems</h2>
            <p style="margin: 5px 0 0 0; font-size: 13px; opacity: 0.9;">New Website Contact Inquiry</p>
        </div>

        <!-- Content -->
        <div style="padding: 25px;">
            <p style="margin-top: 0;">You have received a new contact message from the website contact form:</p>

            <table border="0" cellpadding="8" cellspacing="0" style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px;">
                <tbody>
                    <tr style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <td style="font-weight: bold; width: 35%; color: #475569;">Full Name:</td>
                        <td style="color: #0f172a;">{{ $data['name'] ?? 'N/A' }}</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="font-weight: bold; color: #475569;">Email Address:</td>
                        <td style="color: #0f172a;"><a href="mailto:{{ $data['email'] ?? '' }}" style="color: #007328; text-decoration: none;">{{ $data['email'] ?? 'N/A' }}</a></td>
                    </tr>
                    <tr style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <td style="font-weight: bold; color: #475569;">Phone Number:</td>
                        <td style="color: #0f172a;">{{ $data['phone'] ?? 'N/A' }}</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="font-weight: bold; color: #475569;">Company:</td>
                        <td style="color: #0f172a;">{{ $data['company'] ?? 'N/A' }}</td>
                    </tr>
                    <tr style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <td style="font-weight: bold; color: #475569;">Service Required:</td>
                        <td style="color: #0f172a; font-weight: bold; text-transform: uppercase;">{{ str_replace('_', ' ', $data['serviceType'] ?? 'General Inquiry') }}</td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 20px; padding: 15px; background-color: #f8fafc; border-left: 4px solid #007328; border-radius: 4px;">
                <h4 style="margin: 0 0 8px 0; color: #0f172a; font-size: 14px;">Project Details / Message:</h4>
                <p style="margin: 0; color: #334155; line-height: 1.5; white-space: pre-wrap;">{{ $data['message'] ?? '' }}</p>
            </div>

            <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center;">
                <p style="margin: 0;">This email was sent automatically from Megabyte Circuit Systems contact form.</p>
            </div>
        </div>

    </div>
</body>
</html>
