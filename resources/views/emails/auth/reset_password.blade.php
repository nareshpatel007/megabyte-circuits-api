@extends('emails.layouts.master')

@section('content')

<tr>
    <td class="common-section">
        <div class="greeting">
            Dear <?php echo $data['customer_name']; ?>,
        </div>

        <div class="content">
            We received a request to reset the password for your account.
        </div>
        
        <div class="content">
            Click the link below to create a new password:
        </div>

        <div class="btn-wrapper">
            <a href="<?php echo 'https://connectly360.com/auth/reset?token=' . $data['token']; ?>" class="btn">
                Reset Password
            </a>
        </div>

        <div class="content">
            If you did not request a password reset, please ignore this email or contact our support team.
        </div>
    </td>
</tr>

@endsection