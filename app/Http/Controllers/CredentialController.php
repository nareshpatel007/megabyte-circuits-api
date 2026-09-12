<?php

namespace App\Http\Controllers;

use App\Models\Credential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class CredentialController extends Controller
{
    /**
     * Get all credentials grouped by service with values masked.
     */
    public function index()
    {
        $allCredentials = Credential::all();

        $grouped = [];

        foreach ($allCredentials as $cred) {
            $decrypted = $cred->decrypted_value;
            $grouped[$cred->group][$cred->key] = [
                'masked' => $decrypted,
                'is_set' => !empty($decrypted),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $grouped
        ]);
    }

    /**
     * Update credentials for a group or bulk updates.
     */
    public function update(Request $request)
    {
        $data = $request->json()->all();

        // Data expected format: { "razorpay": { "RAZORPAY_TEST_KEY_ID": "...", ... }, "jlcpcb": { ... } }
        // OR simple flat key-value pairs: { "RAZORPAY_TEST_KEY_ID": "...", "group": "razorpay" }

        if (empty($data)) {
            return response()->json(['success' => false, 'message' => 'No data provided'], 400);
        }

        $updatedKeys = [];

        foreach ($data as $groupOrKey => $payload) {
            if (is_array($payload)) {
                $group = $groupOrKey;
                foreach ($payload as $key => $value) {
                    $this->updateSingleCredential($group, $key, $value);
                    $updatedKeys[] = $key;
                }
            } else {
                // Flat format: group field required in payload or request
                $group = $request->input('group', 'general');
                $key = $groupOrKey;
                if ($key !== 'group') {
                    $this->updateSingleCredential($group, $key, $payload);
                    $updatedKeys[] = $key;
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Credentials updated successfully',
            'updated' => $updatedKeys
        ]);
    }

    private function updateSingleCredential(string $group, string $key, ?string $value)
    {
        if ($value === null) {
            return;
        }

        // If the value provided matches the masked pattern, it means user didn't change it -> skip update
        if (Credential::isMasked($value)) {
            return;
        }

        $credential = Credential::firstOrNew(['key' => $key]);
        $credential->group = $group;

        if (trim($value) === '') {
            $credential->value = null;
        } else {
            $credential->setEncryptedValue(trim($value));
        }

        $credential->save();
    }
}
