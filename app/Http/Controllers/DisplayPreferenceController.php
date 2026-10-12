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
        abort_if(CentralDisplayPreferences::centralUserId($request->user()) <= 0, 401);

        return response()->json($sync->syncForClient($request))->header('Cache-Control', 'no-store');
    }
}
