<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function submitContact(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:45',
                'category' => 'required|string|max:100',
                'message' => 'required|string'
            ]);

            DB::table('contact_messages')->insert([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'category' => $validated['category'],
                'message' => $validated['message'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Contact message submitted successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to submit message: ' . $th->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $contacts = DB::table('contact_messages')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $contacts
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch contacts: ' . $th->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:45',
                'category' => 'nullable|string|max:100',
                'message' => 'required|string'
            ]);

            $id = DB::table('contact_messages')->insertGetId([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'category' => $validated['category'] ?? null,
                'message' => $validated['message'],
                'status' => 'pending',
                'ip_address' => $request->ip() ?? '127.0.0.1',
                'user_agent' => $request->userAgent() ?? 'Admin',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Contact created successfully.',
                'data' => DB::table('contact_messages')->where('id', $id)->first()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create contact: ' . $th->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,resolved,closed'
            ]);

            DB::table('contact_messages')
                ->where('id', $id)
                ->update([
                    'status' => $validated['status'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            return response()->json([
                'status' => true,
                'message' => 'Contact status updated successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update contact: ' . $th->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::table('contact_messages')->where('id', $id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Contact deleted successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete contact: ' . $th->getMessage()
            ], 500);
        }
    }
}
