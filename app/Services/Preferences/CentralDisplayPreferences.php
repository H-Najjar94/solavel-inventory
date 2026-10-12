<?php

namespace App\Services\Preferences;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Saves the signed-in user's language/theme as their Solavel account default
 * on Central (POST /api/sso/display-preferences). Best effort: never throws,
 * never blocks the UI beyond a short timeout, logs a warning on failure.
 */
class CentralDisplayPreferences
{
    public const LOCALES = ['en', 'ar', 'fr', 'ru', 'ur', 'de', 'es', 'tr', 'it', 'pt', 'zh'];
    public const THEMES = ['light', 'dark'];

    public function save(int $centralUserId, array $prefs): bool
    {
        $payload = ['central_user_id' => $centralUserId];
        if (in_array($prefs['preferred_locale'] ?? null, self::LOCALES, true)) {
            $payload['preferred_locale'] = $prefs['preferred_locale'];
        }
        if (in_array($prefs['preferred_theme'] ?? null, self::THEMES, true)) {
            $payload['preferred_theme'] = $prefs['preferred_theme'];
        }
        $secret = (string) config('sso.shared_secret');
        if ($centralUserId <= 0 || count($payload) < 2 || $secret === '') {
            Log::warning('[DisplayPreferences] Not saved to Central: missing user, preference or SSO secret', ['central_user_id' => $centralUserId]);

            return false;
        }

        try {
            $base = rtrim((string) (config('sso.central_app_url') ?: 'https://solavel.com'), '/');
            $response = Http::acceptJson()->asJson()->connectTimeout(2)->timeout(4)->withoutRedirecting()
                ->withHeaders(['X-SSO-SECRET' => $secret])
                ->post($base.'/api/sso/display-preferences', $payload);
            if ($response->successful()) {
                return true;
            }
            Log::warning('[DisplayPreferences] Central rejected the preference save', ['central_user_id' => $centralUserId, 'status' => $response->status()]);
        } catch (\Throwable $e) {
            Log::warning('[DisplayPreferences] Central preference save failed', ['central_user_id' => $centralUserId, 'error' => $e->getMessage()]);
        }

        return false;
    }

    /** Central user id of the signed-in user (the SSO handoff logs in the Central user row itself). */
    public static function centralUserId(mixed $user): int
    {
        if (! $user) {
            return 0;
        }

        return (int) (($user->central_user_id ?? null) ?: ($user->id ?? 0));
    }
}
