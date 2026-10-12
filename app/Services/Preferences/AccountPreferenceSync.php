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
    public const LOCALES = ['en', 'ar'];

    private const CHECKED_AT_KEY = 'account_display_preferences_checked_at';
    private const FINGERPRINT_KEY = 'account_display_preferences_fingerprint';
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
            if ($checkedAt > 0 && $now - $checkedAt < self::THROTTLE_SECONDS) {
                return $result;
            }
            $session->put(self::CHECKED_AT_KEY, $now);

            $account = $this->central->fetch($centralUserId);
            if ($account === null) {
                return $result; // Central unavailable: keep everything as is.
            }

            $fingerprint = $centralUserId.'|'.($account['locale'] ?? '').'|'.($account['theme'] ?? '');
            if ($session->get(self::FINGERPRINT_KEY) === $fingerprint) {
                return $result;
            }

            $values = [];
            if (isset($account['locale'])) {
                // Account languages this app does not offer fall back to English.
                $values['locale'] = in_array($account['locale'], self::LOCALES, true) ? $account['locale'] : 'en';
            }
            if (isset($account['theme'])) {
                $values['theme'] = $account['theme'];
            }
            DisplayPreferences::hydrate($request, $values, $centralUserId, self::LOCALE_KEY);
            $session->put(self::FINGERPRINT_KEY, $fingerprint);
            $session->put(self::PENDING_KEY, true);

            $locale = $session->get(self::LOCALE_KEY);
            $theme = $values['theme'] ?? null;
            $result = [
                'changed' => $locale !== $result['locale'] || ($theme !== null && $theme !== $result['theme']),
                'locale' => $locale,
                'theme' => $theme ?? $result['theme'],
            ];
        } catch (\Throwable $e) {
            report($e);
        }

        return $result;
    }
}
