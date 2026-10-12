<?php

namespace App\Http\Controllers;

use App\Services\Preferences\AccountPreferenceSync;
use App\Services\Preferences\CentralDisplayPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Theme toggles save the chosen theme as the signed-in user's Solavel account default. */
class DisplayPreferenceController extends Controller
{
    public function theme(Request $request, CentralDisplayPreferences $preferences): JsonResponse
    {
        if ($request->has('remember_language')) {
            $data = $request->validate(['remember_language' => ['required', 'boolean'], 'locale' => ['sometimes', 'string', 'in:en,ar,fr,ru,ur,de,es,tr,it,pt,zh']]);
            $id = CentralDisplayPreferences::centralUserId($request->user());
            abort_if($id <= 0, 401);
            $prefs = ['remember_language' => (bool) $data['remember_language']];
            if ($prefs['remember_language'] && isset($data['locale'])) $prefs['preferred_locale'] = $data['locale'];
            $saved = $preferences->save($id, $prefs);
            return response()->json(['saved' => $saved], $saved ? 200 : 502);
        }

        $centralUserId = CentralDisplayPreferences::centralUserId($request->user());
        abort_if($centralUserId <= 0, 401);
        $theme = $request->input('theme');
        if (! in_array($theme, CentralDisplayPreferences::THEMES, true)) {
            return response()->json(['success' => false, 'message' => 'Invalid theme.'], 422);
        }

        $saved = $preferences->save($centralUserId, ['preferred_theme' => $theme]);

        return response()->json(['success' => true, 'saved' => $saved]);
    }

    /** Poll from the open page: has the account language/theme changed elsewhere? */
    public function sync(Request $request, AccountPreferenceSync $sync): JsonResponse
    {
        if ($request->boolean('language_default')) {
            $id = CentralDisplayPreferences::centralUserId($request->user());
            abort_if($id <= 0, 401);
            $account = app(\App\Services\Preferences\CentralDisplayPreferences::class)->fetch($id);
            return response()->json(['remember_language' => $account['remember_language'] ?? null], $account === null ? 502 : 200)->header('Cache-Control', 'no-store');
        }

        abort_if(CentralDisplayPreferences::centralUserId($request->user()) <= 0, 401);

        return response()->json($sync->syncForClient($request))->header('Cache-Control', 'no-store');
    }
}
