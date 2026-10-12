<?php

namespace App\Http\Controllers;

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
        $data = $request->validate(['theme' => ['required', 'string', 'in:light,dark']]);

        $saved = $preferences->save($centralUserId, ['preferred_theme' => $data['theme']]);

        return response()->json(['success' => true, 'saved' => $saved]);
    }
}
