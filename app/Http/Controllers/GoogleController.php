<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Firebase\JWT\JWT;

class GoogleController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function redirect()
    {
        try {
            return Socialite::driver('google')->stateless()->redirect();
        } catch (Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect($frontendUrl . '/login?error=' . urlencode('Failed to initialize Google login: ' . $e->getMessage()));
        }
    }

    /**
     * Obtain the user information from Google OAuth callback.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback(Request $request)
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        try {
            // Check if user denied access or error returned
            if ($request->has('error')) {
                $errorReason = $request->input('error_description', $request->input('error', 'Access denied'));
                return redirect($frontendUrl . '/login?error=' . urlencode('Google login canceled: ' . $errorReason));
            }

            // Retrieve user details from Google Socialite (stateless)
            $googleUser = Socialite::driver('google')->stateless()->user();

            if (!$googleUser || empty($googleUser->getEmail())) {
                return redirect($frontendUrl . '/login?error=' . urlencode('Unable to retrieve email from Google user account.'));
            }

            $email = $googleUser->getEmail();
            $name = $googleUser->getName() ?? $googleUser->getNickname() ?? explode('@', $email)[0];
            $googleId = $googleUser->getId();
            $avatar = $googleUser->getAvatar();

            // Find existing user by email or google_id
            $user = User::where('email', $email)
                ->orWhere('google_id', $googleId)
                ->first();

            if (!$user) {
                // Register new user
                $uuid = (string) Str::uuid();
                
                do {
                    $referralCode = strtoupper(Str::random(8));
                } while (User::where('referral_code', $referralCode)->exists());

                $nameParts = explode(' ', trim($name));
                $firstName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

                $user = User::create([
                    'uuid' => $uuid,
                    'name' => $name,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'api_key' => Str::random(32),
                    'referral_code' => $referralCode,
                    'status' => 'active',
                    'available_credits' => 50,
                    'total_bonus_credits' => 50,
                    'last_login_at' => now(),
                    'last_login_ip' => $request->ip(),
                ]);

                // Create initial wallet bonus transaction
                try {
                    DB::table('wallet_transactions')->insert([
                        'user_id' => $user->id,
                        'transaction_type' => 'signup_bonus',
                        'credit_type' => 'credit',
                        'credits' => 50,
                        'opening_balance' => 0,
                        'closing_balance' => 50,
                        'remarks' => '50 FREE Credits added on successful Google Sign-In registration.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    // Ignore non-critical wallet insertion error if table schema varies
                }

                // Log activity
                if (class_exists('\App\CommonHelper')) {
                    \App\CommonHelper::logActivity($user->id, 'register_google', 'User registered via Google OAuth SSO.', $request);
                }
            } else {
                // Update user details
                $updateData = [
                    'google_id' => $googleId,
                    'avatar' => $avatar ?? $user->avatar,
                    'last_login_at' => now(),
                    'last_login_ip' => $request->ip(),
                ];

                if (empty($user->name)) {
                    $updateData['name'] = $name;
                }
                if (empty($user->api_key)) {
                    $updateData['api_key'] = Str::random(32);
                }

                $user->update($updateData);

                if (class_exists('\App\CommonHelper')) {
                    \App\CommonHelper::logActivity($user->id, 'login_google', 'User logged in via Google OAuth SSO.', $request);
                }
            }

            // Generate Access Token
            $accessToken = null;

            // 1. Try Laravel Passport if configured on model
            if (method_exists($user, 'createToken')) {
                try {
                    $tokenResult = $user->createToken('GoogleOAuthToken');
                    $accessToken = $tokenResult->accessToken ?? $tokenResult->plainTextToken ?? null;
                } catch (\Throwable $e) {
                    $accessToken = null;
                }
            }

            // 2. Generate JWT Token (Matching application's JWT standard)
            if (!$accessToken) {
                $payload = [
                    'user_id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'credits' => $user->available_credits ?? 50,
                    'avatar' => $user->avatar,
                    'iat' => time(),
                    'exp' => time() + (86400 * 30), // 30 days
                ];

                $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
                $accessToken = JWT::encode($payload, $secret, 'HS256');
            }

            // Redirect to frontend success page with token
            $redirectUrl = $frontendUrl . '/login-success?token=' . urlencode($accessToken);
            return redirect($redirectUrl);

        } catch (Exception $e) {
            return redirect($frontendUrl . '/login?error=' . urlencode('Google authentication failed: ' . $e->getMessage()));
        }
    }
}
