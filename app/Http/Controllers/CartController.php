<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class CartController extends Controller
{
    /**
     * Resolve authenticated user ID from request attributes, JWT bearer token, or validated input
     */
    private function resolveUserId(Request $request): ?int
    {
        $authUser = $request->attributes->get('authenticated_user');
        if ($authUser && isset($authUser->id)) {
            return (int)$authUser->id;
        }

        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = str_replace('Bearer ', '', $authHeader);
            try {
                $secret = \App\Services\CredentialService::get('auth', 'JWT_SECRET', 'JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
                $decoded = JWT::decode($token, new Key($secret, 'HS256'));
                if (!empty($decoded->user_id)) {
                    return (int)$decoded->user_id;
                }
            } catch (\Throwable $e) {
                // Not a valid user JWT
            }
        }

        $inputUserId = $request->input('user_id');
        if ($inputUserId && is_numeric($inputUserId)) {
            $exists = DB::table('users')->where('id', (int)$inputUserId)->exists();
            if ($exists) {
                return (int)$inputUserId;
            }
        }

        return null;
    }

    /**
     * Canonical cart session ID for an authenticated user across all devices
     */
    public static function getUserCartSessionId(int $userId): string
    {
        return "user_cart_{$userId}";
    }

    /**
     * Save or update cart items by session ID and/or authenticated user
     */
    public function save(Request $request)
    {
        $userId = $this->resolveUserId($request);
        $sessionId = $request->input('session_id');
        $items = $request->input('items', []);

        // If user is authenticated, ensure canonical session ID is used across all devices
        if ($userId) {
            $canonicalSessionId = self::getUserCartSessionId($userId);
            if (!$sessionId || str_starts_with($sessionId, 'cart_sess_')) {
                $sessionId = $canonicalSessionId;
            }
        }

        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'message' => 'Session ID is required'
            ], 400);
        }

        // Validate delivery date for each item in cart
        if (is_array($items)) {
            foreach ($items as $item) {
                $deliveryDate = $item['delivery_date'] ?? $item['deliveryDate'] ?? null;
                if ($deliveryDate) {
                    $validation = \App\Services\DeliveryCalendarService::validateDeliveryDate($deliveryDate);
                    if (!$validation['valid']) {
                        return response()->json([
                            'success' => false,
                            'message' => $validation['reason']
                        ], 400);
                    }
                }
            }
        }

        $cartDataString = is_array($items) || is_object($items) ? json_encode($items) : $items;

        $updateData = ['cart_data' => $cartDataString];
        if ($userId) {
            $updateData['user_id'] = $userId;
        }

        $cart = Cart::updateOrCreate(
            ['session_id' => $sessionId],
            $updateData
        );

        return response()->json([
            'success' => true,
            'message' => 'Cart saved successfully',
            'session_id' => $sessionId,
            'user_id' => $userId,
            'items' => json_decode($cart->cart_data, true) ?? []
        ]);
    }

    /**
     * Get cart items by session ID or authenticated user
     */
    public function get(Request $request)
    {
        $userId = $this->resolveUserId($request);
        $sessionId = $request->query('session_id') ?? $request->input('session_id');

        $cart = null;

        // If user is authenticated, look up their persistent cart first
        if ($userId) {
            $canonicalSessionId = self::getUserCartSessionId($userId);
            $cart = Cart::where('user_id', $userId)
                ->orWhere('session_id', $canonicalSessionId)
                ->first();

            if ($cart) {
                $sessionId = $cart->session_id ?: $canonicalSessionId;
            } else {
                $sessionId = $canonicalSessionId;
            }
        }

        // Fallback to query by session_id if cart not found or guest
        if (!$cart && $sessionId) {
            $cart = Cart::where('session_id', $sessionId)->first();
        }

        if (!$sessionId && !$cart) {
            return response()->json([
                'success' => false,
                'message' => 'Session ID or authenticated user is required'
            ], 400);
        }

        if (!$cart || !$cart->cart_data) {
            return response()->json([
                'success' => true,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'items' => []
            ]);
        }

        $items = json_decode($cart->cart_data, true) ?? [];

        return response()->json([
            'success' => true,
            'session_id' => $cart->session_id ?? $sessionId,
            'user_id' => $cart->user_id ?? $userId,
            'items' => $items
        ]);
    }

    /**
     * Attach / merge guest cart to logged-in user and return uniform session ID across all devices
     */
    public function attach(Request $request)
    {
        $userId = $this->resolveUserId($request);
        $guestSessionId = $request->input('guest_session_id') ?? $request->input('session_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Authenticated user is required to attach cart'
            ], 401);
        }

        $canonicalSessionId = self::getUserCartSessionId($userId);

        // Find or prepare the user's primary cart
        $userCart = Cart::where('user_id', $userId)
            ->orWhere('session_id', $canonicalSessionId)
            ->first();

        $userItems = ($userCart && $userCart->cart_data)
            ? (json_decode($userCart->cart_data, true) ?: [])
            : [];

        $guestCart = null;
        if (!empty($guestSessionId) && $guestSessionId !== $canonicalSessionId) {
            $guestCart = Cart::where('session_id', $guestSessionId)->first();
        }

        $guestItems = ($guestCart && $guestCart->cart_data)
            ? (json_decode($guestCart->cart_data, true) ?: [])
            : [];

        // Merge guest items into user items
        $mergedItems = $userItems;
        if (!empty($guestItems)) {
            $existingItemIds = [];
            foreach ($userItems as $idx => $item) {
                if (!empty($item['id'])) {
                    $existingItemIds[strval($item['id'])] = $idx;
                }
            }

            foreach ($guestItems as $gItem) {
                $gId = !empty($gItem['id']) ? strval($gItem['id']) : null;
                if ($gId && isset($existingItemIds[$gId])) {
                    $mergedItems[$existingItemIds[$gId]] = $gItem;
                } else {
                    $mergedItems[] = $gItem;
                    if ($gId) {
                        $existingItemIds[$gId] = count($mergedItems) - 1;
                    }
                }
            }
        }

        // Save into the canonical user cart
        $cartDataString = json_encode(array_values($mergedItems));

        if ($userCart) {
            $userCart->session_id = $canonicalSessionId;
            $userCart->user_id = $userId;
            $userCart->cart_data = $cartDataString;
            $userCart->save();
        } else {
            $userCart = Cart::create([
                'session_id' => $canonicalSessionId,
                'user_id' => $userId,
                'cart_data' => $cartDataString
            ]);
        }

        // Clean up the guest cart record if it was separate
        if ($guestCart && $guestCart->id !== $userCart->id) {
            $guestCart->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Cart successfully attached to user',
            'session_id' => $canonicalSessionId,
            'user_id' => $userId,
            'items' => array_values($mergedItems)
        ]);
    }
}
