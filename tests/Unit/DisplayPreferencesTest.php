<?php

namespace Tests\Unit;

use App\Support\DisplayPreferences;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

class DisplayPreferencesTest extends TestCase
{
    public function test_validated_sso_defaults_set_language_without_altering_access(): void
    {
        $previous = Container::getInstance();
        $app = new class extends Container {
            public string $locale = 'en';
            public function setLocale($locale) { $this->locale = $locale; }
        };
        Container::setInstance($app);
        $app->instance('cookie', new CookieJar);
        try {
            $request = Request::create('https://solavel.com/');
            $session = new Store('display-test', new ArraySessionHandler(120));
            $session->start(); $session->put('roles', ['accountant']); $session->put('permissions', ['invoice.read']);
            $request->setLaravelSession($session);
            DisplayPreferences::hydrate($request, ['locale' => 'ar', 'theme' => 'dark', 'permissions' => ['all']], 42);
            $this->assertSame(['locale' => 'ar', 'theme' => 'dark'], $session->get('solavel_display_preferences'));
            $this->assertSame('ar', $session->get('locale'));
            $this->assertSame('ar', $app->locale);
            $this->assertSame(['accountant'], $session->get('roles'));
            $this->assertSame(['invoice.read'], $session->get('permissions'));
            DisplayPreferences::hydrate($request, ['locale' => '../../bad', 'theme' => 'system'], 43, 'solastock_locale');
            $this->assertSame([], $session->get('solavel_display_preferences'));
            $this->assertSame(43, $session->get('display_preferences_user_id'));
            DisplayPreferences::hydrate($request, ['locale' => 'en', 'theme' => 'light'], 43, 'solastock_locale');
            $this->assertSame('en', $session->get('solastock_locale'));
            DisplayPreferences::hydrate($request, null, 43);
            $this->assertSame([], $session->get('solavel_display_preferences'));
        } finally {
            Container::setInstance($previous);
        }
    }
}
