@extends('emails.layouts.master')

@section('content')

<tr>
    <td class="common-section">
        <div class="greeting">
            Dear <?php echo $data['customer_name']; ?>,
        </div>

        <div class="content">
            Your account has been created successfully.
        </div>

        <div class="content">
            Here are your login credentials:<br>
            <b>Email:</b> <?php echo $data['email']; ?><br>
            <b>Password:</b> <?php echo $data['password']; ?>
        </div>

        <div class="btn-wrapper">
            <a href="https://connectly360.com/auth/login" class="btn">
                Sign In Now
            </a>
        </div>

        <div class="content">
            After login you can change your password from your profile page.
        </div>
        
        <div class="content">
            If you did not sign up for a Connectly360 account, please ignore this email.
        </div>
    </td>
</tr>

@endsection