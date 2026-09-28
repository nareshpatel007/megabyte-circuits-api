<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class UserAvatarService
{
    /**
     * Resolve avatar URLs and avatar source for a user object or array.
     */
    public static function resolveAvatarInfo($user): array
    {
        if (!$user) {
            return [
                'avatar_url' => null,
                'avatar_source' => 'default',
                'custom_avatar_url' => null,
                'google_avatar_url' => null,
            ];
        }

        $userObj = is_array($user) ? (object)$user : $user;

        $customAvatarRaw = $userObj->custom_avatar ?? null;
        $googleAvatarRaw = $userObj->google_avatar ?? null;
        $avatarRaw = $userObj->avatar ?? null;
        $updatedAt = $userObj->updated_at ?? time();

        // 1. Custom Avatar URL
        $customAvatarUrl = null;
        if (!empty($customAvatarRaw)) {
            if (str_starts_with($customAvatarRaw, 'http://') || str_starts_with($customAvatarRaw, 'https://')) {
                $customAvatarUrl = $customAvatarRaw;
            } else {
                $cleanPath = ltrim($customAvatarRaw, '/');
                if (str_starts_with($cleanPath, 'storage/')) {
                    $cleanPath = substr($cleanPath, 8);
                }
                $customAvatarUrl = url(Storage::url($cleanPath));
            }
            // Add cache busting query parameter if updated_at is available
            $ts = is_numeric($updatedAt) ? $updatedAt : strtotime((string)$updatedAt);
            if ($ts) {
                $customAvatarUrl .= (str_contains($customAvatarUrl, '?') ? '&' : '?') . 'v=' . $ts;
            }
        }

        // 2. Google Avatar URL
        $googleAvatarUrl = null;
        if (!empty($googleAvatarRaw)) {
            $googleAvatarUrl = $googleAvatarRaw;
        } elseif (!empty($avatarRaw) && (str_starts_with($avatarRaw, 'http://') || str_starts_with($avatarRaw, 'https://') || str_contains($avatarRaw, 'googleusercontent'))) {
            $googleAvatarUrl = $avatarRaw;
        }

        // 3. Fallback for avatar field if custom_avatar and google_avatar weren't explicitly set yet
        $legacyAvatarUrl = null;
        if (!empty($avatarRaw) && empty($customAvatarUrl) && empty($googleAvatarUrl)) {
            if (str_starts_with($avatarRaw, 'http://') || str_starts_with($avatarRaw, 'https://')) {
                $legacyAvatarUrl = $avatarRaw;
            } else {
                $cleanPath = ltrim($avatarRaw, '/');
                if (str_starts_with($cleanPath, 'storage/')) {
                    $cleanPath = substr($cleanPath, 8);
                }
                $legacyAvatarUrl = url(Storage::url($cleanPath));
            }
        }

        // 4. Priority Resolution: Custom > Google > Legacy > Default (null)
        $resolvedUrl = null;
        $source = 'default';

        if ($customAvatarUrl) {
            $resolvedUrl = $customAvatarUrl;
            $source = 'custom';
        } elseif ($googleAvatarUrl) {
            $resolvedUrl = $googleAvatarUrl;
            $source = 'google';
        } elseif ($legacyAvatarUrl) {
            $resolvedUrl = $legacyAvatarUrl;
            $source = (str_starts_with($avatarRaw, 'http') || str_contains((string)$avatarRaw, 'google')) ? 'google' : 'custom';
        }

        return [
            'avatar_url' => $resolvedUrl,
            'avatar_source' => $source, // 'custom' | 'google' | 'default'
            'custom_avatar_url' => $customAvatarUrl,
            'google_avatar_url' => $googleAvatarUrl,
        ];
    }

    /**
     * Format standard user response data array including avatar details.
     */
    public static function formatUserData($user): array
    {
        if (!$user) {
            return [];
        }

        $u = is_array($user) ? (object)$user : $user;
        $avatarInfo = self::resolveAvatarInfo($u);

        $name = $u->name ?? '';
        if (empty($name)) {
            $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        }
        if (empty($name) && !empty($u->email)) {
            $name = explode('@', $u->email)[0];
        }

        return [
            'id' => (int)$u->id,
            'uuid' => $u->uuid ?? null,
            'name' => $name,
            'first_name' => $u->first_name ?? '',
            'last_name' => $u->last_name ?? '',
            'email' => $u->email ?? '',
            'status' => strtolower(trim((string)($u->status ?? 'active'))),
            'company_name' => $u->company_name ?? '',
            'phone_number' => $u->phone_number ?? '',
            'country' => $u->country ?? null,
            'gst_number' => $u->gst_number ?? null,
            'custom_avatar' => $avatarInfo['custom_avatar_url'],
            'google_avatar' => $avatarInfo['google_avatar_url'],
            'avatar_url' => $avatarInfo['avatar_url'],
            'avatar_source' => $avatarInfo['avatar_source'],
            'avatar' => $avatarInfo['avatar_url'],
            'google_id' => $u->google_id ?? null,
            'is_google_user' => !empty($u->google_id) || !empty($avatarInfo['google_avatar_url']),
            'available_credits' => intval($u->available_credits ?? 0),
            'total_bonus_credits' => intval($u->total_bonus_credits ?? 0),
            'created_at' => $u->created_at ?? null,
            'updated_at' => $u->updated_at ?? null,
        ];
    }
}
