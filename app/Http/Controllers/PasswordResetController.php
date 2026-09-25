<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PasswordResetService;

class PasswordResetController extends Controller
{
    /**
     * Client: Request Password Reset OTP
     */
    public function clientForgotPassword(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $identifier = $input['email'] ?? $input['username'] ?? $input['identifier'] ?? $request->input('email');
            $ip = $request->ip();
            $userAgent = $request->userAgent();

            $result = PasswordResetService::requestOtp((string)$identifier, 'client', $ip, $userAgent);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process forgot password request: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Client: Verify Password Reset OTP
     */
    public function clientVerifyOtp(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $identifier = $input['email'] ?? $input['username'] ?? $input['identifier'] ?? $request->input('email');
            $otp = $input['otp'] ?? $request->input('otp');

            $result = PasswordResetService::verifyOtp((string)$identifier, (string)$otp, 'client');

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify code: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Client: Resend Password Reset OTP
     */
    public function clientResendOtp(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $identifier = $input['email'] ?? $input['username'] ?? $input['identifier'] ?? $request->input('email');
            $ip = $request->ip();
            $userAgent = $request->userAgent();

            $result = PasswordResetService::requestOtp((string)$identifier, 'client', $ip, $userAgent);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend code: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Client: Reset Password with Reset Token
     */
    public function clientResetPassword(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $resetToken = $input['reset_token'] ?? $input['token'] ?? $request->input('reset_token');
            $password = $input['password'] ?? $request->input('password');
            $passwordConfirmation = $input['password_confirmation'] ?? $input['confirm_password'] ?? $request->input('password_confirmation');

            $result = PasswordResetService::resetPassword((string)$resetToken, (string)$password, (string)$passwordConfirmation, 'client');

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update password: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Request Password Reset OTP
     */
    public function adminForgotPassword(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $identifier = $input['username'] ?? $input['email'] ?? $input['mobile'] ?? $input['identifier'] ?? $request->input('email');
            $ip = $request->ip();
            $userAgent = $request->userAgent();

            $result = PasswordResetService::requestOtp((string)$identifier, 'admin', $ip, $userAgent);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process admin forgot password request: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Verify Password Reset OTP
     */
    public function adminVerifyOtp(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $identifier = $input['username'] ?? $input['email'] ?? $input['mobile'] ?? $input['identifier'] ?? $request->input('email');
            $otp = $input['otp'] ?? $request->input('otp');

            $result = PasswordResetService::verifyOtp((string)$identifier, (string)$otp, 'admin');

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify admin OTP code: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Resend Password Reset OTP
     */
    public function adminResendOtp(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $identifier = $input['username'] ?? $input['email'] ?? $input['mobile'] ?? $input['identifier'] ?? $request->input('email');
            $ip = $request->ip();
            $userAgent = $request->userAgent();

            $result = PasswordResetService::requestOtp((string)$identifier, 'admin', $ip, $userAgent);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend admin OTP code: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Reset Password with Reset Token
     */
    public function adminResetPassword(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $resetToken = $input['reset_token'] ?? $input['token'] ?? $request->input('reset_token');
            $password = $input['password'] ?? $request->input('password');
            $passwordConfirmation = $input['password_confirmation'] ?? $input['confirm_password'] ?? $request->input('password_confirmation');

            $result = PasswordResetService::resetPassword((string)$resetToken, (string)$password, (string)$passwordConfirmation, 'admin');

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update admin password: ' . $th->getMessage()
            ], 500);
        }
    }
}
