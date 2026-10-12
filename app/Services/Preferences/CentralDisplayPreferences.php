<?php

namespace App\Services\Preferences;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Saves (and reads back) the signed-in user's language/theme as their Solavel account default
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
        if (is_bool($prefs['remember_language'] ?? null)) $payload['remember_language'] = $prefs['remember_language'];
        if (($prefs['remember_language_migrate'] ?? false) === true) $payload['remember_language_migrate'] = true;

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

    /**
     * Read the account's saved display preferences from Central
     * (GET /api/sso/display-preferences). Returns ['locale' => ?, 'theme' => ?]
     * with only known values kept (keys omitted when unset), or null when
     * Central could not be asked or answered badly. Never throws.
     */
    public function fetch(int $centralUserId): ?array
    {
        $secret = (string) config('sso.shared_secret');
        if ($centralUserId <= 0 || $secret === '') {
            return null;
        }

        try {
            $base = rtrim((string) (config('sso.central_app_url') ?: 'https://solavel.com'), '/');
            $response = Http::acceptJson()->connectTimeout(2)->timeout(2)->withoutRedirecting()
                ->withHeaders(['X-SSO-SECRET' => $secret])
                ->get($base.'/api/sso/display-preferences', ['central_user_id' => $centralUserId]);
            $values = $response->successful() ? $response->json('display_preferences') : null;
            if (! is_array($values)) {
                return null;
            }
            $preferences = [];
            if (is_string($values['locale'] ?? null) && in_array($values['locale'], self::LOCALES, true)) {
                $preferences['locale'] = $values['locale'];
            }
            if (in_array($values['theme'] ?? null, self::THEMES, true)) {
                $preferences['theme'] = $values['theme'];
            }

            if (is_bool($values['remember_language'] ?? null)) $preferences['remember_language'] = $values['remember_language'];
            return $preferences;
        } catch (\Throwable $e) {
            Log::warning('[DisplayPreferences] Central preference read failed', ['central_user_id' => $centralUserId, 'error' => $e->getMessage()]);
        }

        return null;
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
