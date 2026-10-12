<?php

namespace Tests\Feature\Locale;

use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Theme toggles and the remembered language picker save the Solavel account default on Central. DB-free. */
class DisplayPreferenceSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sso.shared_secret' => 'test-sso-secret', 'sso.central_app_url' => 'https://central.test/']);
    }

    private function user(int $id = 42): User
    {
        $user = new User;
        $user->forceFill(['id' => $id, 'name' => 'Pref Tester', 'email' => 'pref@example.test']);
        $user->exists = true;

        return $user;
    }

    public function test_theme_change_is_saved_to_central(): void
    {
        Http::fake(['central.test/*' => Http::response(['saved' => true])]);

        $this->actingAs($this->user())->postJson('/preferences/theme', ['theme' => 'dark'])
            ->assertOk()->assertJson(['success' => true, 'saved' => true]);

        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://central.test/api/sso/display-preferences'
            && $r->method() === 'POST'
            && $r->hasHeader('X-SSO-SECRET', 'test-sso-secret')
            && $r->data() === ['central_user_id' => 42, 'preferred_theme' => 'dark']);
    }

    public function test_theme_route_never_fails_when_central_is_down(): void
    {
        Http::fake(['central.test/*' => Http::response(['message' => 'down'], 503)]);

        $this->actingAs($this->user())->postJson('/preferences/theme', ['theme' => 'light'])
            ->assertOk()->assertJson(['success' => true, 'saved' => false]);
    }

    public function test_theme_route_rejects_guests_and_invalid_values(): void
    {
        Http::fake();

        $this->postJson('/preferences/theme', ['theme' => 'dark'])->assertStatus(401);
        $this->actingAs($this->user())->postJson('/preferences/theme', ['theme' => 'system'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_language_change_with_remember_saves_the_account_default(): void
    {
        Http::fake(['central.test/*' => Http::response(['saved' => true])]);

        $response = $this->actingAs($this->user(7))->get('/dashboard?locale=ar&remember=1');

        $response->assertRedirect();
        $this->assertStringNotContainsString('remember', $response->headers->get('Location'));
        $this->assertStringContainsString('locale=ar', $response->headers->get('Location'));
        $this->assertSame('ar', session('solastock_locale'));
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://central.test/api/sso/display-preferences'
            && $r->data() === ['central_user_id' => 7, 'preferred_locale' => 'ar']);
    }

    public function test_language_change_with_remember_still_switches_when_central_fails(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->actingAs($this->user())->get('/dashboard?locale=ar&remember=1')->assertRedirect();
        $this->assertSame('ar', session('solastock_locale'));
    }

    public function test_language_change_without_remember_does_not_call_central(): void
    {
        Http::fake();

        // A probe route behind the web group keeps this DB-free (no app-access check).
        \Illuminate\Support\Facades\Route::middleware('web')->get('/__locale-probe', fn () => app()->getLocale());

        $this->actingAs($this->user())->get('/__locale-probe?locale=ar')->assertOk()->assertSee('ar');
        $this->assertSame('ar', session('solastock_locale'));
        Http::assertNothingSent();
    }
}
