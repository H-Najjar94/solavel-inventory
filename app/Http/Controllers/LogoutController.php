<?php

namespace App\Http\Controllers;

use App\Services\Access\OtherAppsMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Sign out from the SolaStock account menu: end this app's own session, then
 * hand off to Central's single sign-out (/sso/logout), which ends the Solavel
 * session and walks the other apps' logout chain before landing on sign-in.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $central = OtherAppsMenu::centralBase() ?: 'https://solavel.com';

        return redirect()->away($central.'/sso/logout?'.http_build_query(['to' => '/login']))
            ->withCookie(Cookie::forget('inv_sso_tried'));
    }
}
