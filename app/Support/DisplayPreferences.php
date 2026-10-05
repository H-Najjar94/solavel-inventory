<?php

namespace App\Support;

use Illuminate\Http\Request;

final class DisplayPreferences
{
    /** Called only after the existing Central SSO authorization succeeds. */
    public static function hydrate(Request $request, mixed $values, int $userId, string $localeKey = 'locale'): void
    {
        $values = is_array($values) ? $values : [];
        $preferences = [];
        if (in_array($values['locale'] ?? null, ['en', 'ar'], true)) $preferences['locale'] = $values['locale'];
        if (in_array($values['theme'] ?? null, ['light', 'dark'], true)) $preferences['theme'] = $values['theme'];
        $request->session()->put('solavel_display_preferences', $preferences);
        $request->session()->put('display_preferences_user_id', $userId);
        if (isset($preferences['locale'])) {
            $request->session()->put($localeKey, $preferences['locale']);
            app()->setLocale($preferences['locale']);
        }
    }
}
