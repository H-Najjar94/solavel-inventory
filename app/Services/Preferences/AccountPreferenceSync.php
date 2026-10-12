<?php

namespace App\Services\Preferences;

use App\Support\DisplayPreferences;
use Illuminate\Http\Request;

/**
 * Keeps a signed-in session following the person's Solavel ACCOUNT language and
 * theme, so a change saved in another app applies here without signing in again.
 *
 * At most once per THROTTLE_SECONDS per session, Central is asked for the account
 * preferences. Only when they differ from the last account values this session
 * saw (or none were seen yet) are they applied, the same way the SSO handoff
 * applies them. A language picked only for this session therefore survives until
 * the account preference itself changes again. Never throws.
 */
class AccountPreferenceSync
{
    public const THROTTLE_SECONDS = 30;
    public const LOCALES = ['en', 'ar', 'fr', 'ru', 'ur', 'de', 'es', 'zh', 'pt', 'tr', 'it'];

    private const CHECKED_AT_KEY = 'account_display_preferences_checked_at';
    private const FINGERPRINT_KEY = 'account_display_preferences_fingerprint_v2';
    private const PENDING_KEY = 'account_display_preferences_pending';
    private const RESULT_ATTRIBUTE = 'account_display_preferences_sync';
    private const LOCALE_KEY = 'solastock_locale';

    public function __construct(private CentralDisplayPreferences $central) {}

    /** @return array{changed: bool, locale: ?string, theme: ?string} */
    public function sync(Request $request): array
    {
        if ($request->attributes->has(self::RESULT_ATTRIBUTE)) {
            return $request->attributes->get(self::RESULT_ATTRIBUTE);
        }

        $result = $this->run($request);
        $request->attributes->set(self::RESULT_ATTRIBUTE, $result);

        return $result;
    }

    /**
     * Same check for the open page's poll. Also reports a change that an earlier
     * request (e.g. an API call) already applied but the page has not shown yet.
     *
     * @return array{changed: bool, locale: ?string, theme: ?string}
     */
    public function syncForClient(Request $request): array
    {
        $result = $this->sync($request);
        try {
            if ($request->hasSession() && $request->session()->pull(self::PENDING_KEY)) {
                $result['changed'] = true;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $result;
    }

    private function run(Request $request): array
    {
        $result = ['changed' => false, 'locale' => null, 'theme' => null];
        try {
            if (! $request->hasSession()) {
                return $result;
            }
            $session = $request->session();
            $result['locale'] = $session->get(self::LOCALE_KEY);
            $result['theme'] = $session->get('solavel_display_preferences.theme');

            $centralUserId = CentralDisplayPreferences::centralUserId($request->user());
            if ($centralUserId <= 0) {
                return $result;
            }
            $now = now()->getTimestamp();
            $checkedAt = (int) $session->get(self::CHECKED_AT_KEY, 0);
            $cached = $session->get(self::FINGERPRINT_KEY);
            $cachedLocale = is_array($cached) ? ($cached['locale'] ?? null) : null;
            $repairLocale = $request->boolean('follow_locale') && is_array($cached)
                && ($cached['user'] ?? null) === $centralUserId && is_string($cachedLocale)
                && $result['locale'] !== (in_array($cachedLocale, self::LOCALES, true) ? $cachedLocale : 'en');
            if ($checkedAt > 0 && $now - $checkedAt < self::THROTTLE_SECONDS && ! $repairLocale) {
                return $result;
            }
            $session->put(self::CHECKED_AT_KEY, $now);

            $account = $this->central->fetch($centralUserId);
            if ($account === null) {
                return $result; // Central unavailable: keep everything as is.
            }

            // Compare each part with the account values this session last saw, so
            // a theme change elsewhere does not undo a session-only language pick.
            $fingerprint = ['user' => $centralUserId, 'locale' => $account['locale'] ?? null, 'theme' => $account['theme'] ?? null];
            $seen = $session->get(self::FINGERPRINT_KEY);
            $seen = is_array($seen) && ($seen['user'] ?? null) === $centralUserId ? $seen : null;
            $accountLocale = $fingerprint['locale'] === null ? null : (in_array($fingerprint['locale'], self::LOCALES, true) ? $fingerprint['locale'] : 'en');
            $localeChanged = $seen === null || ($seen['locale'] ?? null) !== $fingerprint['locale']
                || ($request->boolean('follow_locale') && $accountLocale !== null && $result['locale'] !== $accountLocale);
            $themeChanged = $seen === null || ($seen['theme'] ?? null) !== $fingerprint['theme'];
            if (! $localeChanged && ! $themeChanged) {
                return $result;
            }

            $values = [];
            if ($localeChanged && $fingerprint['locale'] !== null) {
                // Account languages this app does not offer fall back to English.
                $values['locale'] = in_array($fingerprint['locale'], self::LOCALES, true) ? $fingerprint['locale'] : 'en';
            } elseif (is_string($result['locale'])) {
                $values['locale'] = $result['locale'];
            }
            if ($themeChanged && $fingerprint['theme'] !== null) {
                $values['theme'] = $fingerprint['theme'];
            } elseif (is_string($result['theme'])) {
                $values['theme'] = $result['theme'];
            }
            DisplayPreferences::hydrate($request, $values, $centralUserId, self::LOCALE_KEY);
            $session->put(self::FINGERPRINT_KEY, $fingerprint);
            $session->put(self::PENDING_KEY, true);

            $locale = $session->get(self::LOCALE_KEY);
            $theme = $session->get('solavel_display_preferences.theme');
            $result = [
                'changed' => $locale !== $result['locale'] || $theme !== $result['theme'],
                'locale' => $locale,
                'theme' => $theme,
            ];
        } catch (\Throwable $e) {
            report($e);
        }

        return $result;
    }
}
