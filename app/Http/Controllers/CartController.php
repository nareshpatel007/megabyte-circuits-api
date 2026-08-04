<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cart;

class CartController extends Controller
{
    /**
     * Save or update cart items by session ID
     */
    public function save(Request $request)
    {
        $sessionId = $request->input('session_id');
        $items = $request->input('items', []);

        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'message' => 'Session ID is required'
            ], 400);
        }

        $cartDataString = is_array($items) || is_object($items) ? json_encode($items) : $items;

        $cart = Cart::updateOrCreate(
            ['session_id' => $sessionId],
            ['cart_data' => $cartDataString]
        );

        return response()->json([
            'success' => true,
            'message' => 'Cart saved successfully',
            'session_id' => $sessionId,
            'items' => json_decode($cart->cart_data, true) ?? []
        ]);
    }

    /**
     * Get cart items by session ID
     */
    public function get(Request $request)
    {
        $sessionId = $request->query('session_id') ?? $request->input('session_id');

        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'message' => 'Session ID is required'
            ], 400);
        }

        $cart = Cart::where('session_id', $sessionId)->first();

        if (!$cart || !$cart->cart_data) {
            return response()->json([
                'success' => true,
                'session_id' => $sessionId,
                'items' => []
            ]);
        }

        $items = json_decode($cart->cart_data, true) ?? [];

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'items' => $items
        ]);
    }
}
