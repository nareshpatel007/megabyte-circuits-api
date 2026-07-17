@extends('emails.layouts.master')

@section('content')

<tr>
    <td class="common-section">
        <div class="greeting">
            Dear <?php echo $data['customer_name']; ?>,
        </div>

        <div class="content">
            Thank you for registering with Connectly360! To complete your registration and access your account, please verify your email by clicking the link below:
        </div>

        <div class="btn-wrapper">
            <a href="<?php echo 'https://connectly360.com/auth/verification?token=' . $data['token'] . '&redirect=' . $data['redirect_url'] ?? '/'; ?>" class="btn">
                Verify Email
            </a>
        </div>

        <div class="content">
            If you didn’t sign up for a Connectly360 account, please ignore this email. For any issues, please contact our support team.
        </div>
    </td>
</tr>

@endsection