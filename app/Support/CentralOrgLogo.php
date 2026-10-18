<?php

namespace App\Support;

/** Public signed URL of a Central organization's logo (served by Central /org-logos/{id}). */
class CentralOrgLogo
{
    public static function url($centralOrgId): ?string
    {
        $id = (int) $centralOrgId;
        $secret = (string) config('sso.shared_secret', '');
        if ($id <= 0 || $secret === '') {
            return null;
        }
        $base = rtrim((string) (config('sso.central_app_url') ?: config('tenancy.parent_base_url') ?: 'https://solavel.com'), '/');
        $sig = substr(hash_hmac('sha256', 'org-logo|' . $id, $secret), 0, 32);

        return $base . '/org-logos/' . $id . '?s=' . $sig;
    }
}
