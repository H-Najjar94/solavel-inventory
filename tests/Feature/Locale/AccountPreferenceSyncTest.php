<?php

namespace Tests\Feature\Locale;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Signed-in sessions follow the Solavel ACCOUNT language/theme read from Central. DB-free. */
class AccountPreferenceSyncTest extends TestCase
{
    private const URL = 'https://central.test/api/sso/display-preferences?central_user_id=42';

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

    private array $account = [];

    /** Central answers with whatever $this->account holds at request time. */
    private function fakeCentral(array $preferences): void
    {
        $this->account = $preferences;
        Http::fake(['central.test/*' => fn () => Http::response(['display_preferences' => $this->account])]);
    }

    public function test_changed_account_language_and_theme_apply_to_the_session(): void
    {
        $this->fakeCentral(['locale' => 'ar', 'theme' => 'dark']);

        $this->actingAs($this->user())->withSession(['solastock_locale' => 'en'])
            ->getJson('/preferences/sync')
            ->assertOk()->assertExactJson(['changed' => true, 'locale' => 'ar', 'theme' => 'dark']);

        $this->assertSame('ar', session('solastock_locale'));
        $this->assertSame(['locale' => 'ar', 'theme' => 'dark'], session('solavel_display_preferences'));
        $this->assertSame(42, session('display_preferences_user_id'));
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET'
            && $r->url() === self::URL
            && $r->hasHeader('X-SSO-SECRET', 'test-sso-secret'));
    }

    public function test_unchanged_account_preference_keeps_a_session_only_language(): void
    {
        $this->fakeCentral(['locale' => 'ar', 'theme' => 'light']);
        $this->actingAs($this->user())->getJson('/preferences/sync')->assertJson(['changed' => true, 'locale' => 'ar']);

        // Session-only switch to English (no "remember"), then the next check.
        $this->getJson('/preferences/sync?locale=en')->assertOk();
        $this->assertSame('en', session('solastock_locale'));
        $this->travel(31)->seconds();

        $this->getJson('/preferences/sync')->assertOk()
            ->assertExactJson(['changed' => false, 'locale' => 'en', 'theme' => 'light']);
        $this->assertSame('en', session('solastock_locale'));
        Http::assertSentCount(2);

        // The account language changes again elsewhere: now it applies.
        $this->account = ['locale' => 'en', 'theme' => 'dark'];
        $this->travel(31)->seconds();
        $this->getJson('/preferences/sync')->assertExactJson(['changed' => true, 'locale' => 'en', 'theme' => 'dark']);
    }

    public function test_a_theme_change_elsewhere_keeps_a_session_only_language(): void
    {
        $this->fakeCentral(['locale' => 'ar', 'theme' => 'light']);
        $this->actingAs($this->user())->getJson('/preferences/sync')->assertOk();
        $this->getJson('/preferences/sync?locale=en')->assertOk();

        $this->account = ['locale' => 'ar', 'theme' => 'dark'];
        $this->travel(31)->seconds();
        $this->getJson('/preferences/sync')->assertExactJson(['changed' => true, 'locale' => 'en', 'theme' => 'dark']);
        $this->assertSame('en', session('solastock_locale'));
    }

    public function test_supported_french_account_language_is_preserved(): void
    {
        $this->fakeCentral(['locale' => 'fr']);

        $this->actingAs($this->user())->withSession(['solastock_locale' => 'ar'])
            ->getJson('/preferences/sync')->assertOk()->assertJson(['changed' => true, 'locale' => 'fr']);

        $this->assertSame('fr', session('solastock_locale'));
    }

    public function test_central_failure_changes_nothing(): void
    {
        Http::fake(['central.test/*' => Http::response(['message' => 'down'], 503)]);
        $this->actingAs($this->user())->withSession(['solastock_locale' => 'ar'])
            ->getJson('/preferences/sync')->assertOk()->assertJson(['changed' => false, 'locale' => 'ar']);
        $this->assertSame('ar', session('solastock_locale'));
        $this->assertNull(session('account_display_preferences_fingerprint'));

        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->travel(31)->seconds();
        $this->getJson('/preferences/sync')->assertOk()->assertJson(['changed' => false, 'locale' => 'ar']);
        $this->assertSame('ar', session('solastock_locale'));
    }

    public function test_central_is_asked_at_most_once_per_30_seconds(): void
    {
        $this->fakeCentral(['locale' => 'ar']);

        $this->actingAs($this->user())->getJson('/preferences/sync')->assertOk();
        $this->travel(20)->seconds();
        $this->getJson('/preferences/sync')->assertOk();
        Http::assertSentCount(1);

        $this->travel(11)->seconds();
        $this->getJson('/preferences/sync')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_a_change_applied_by_an_earlier_request_is_still_reported_to_the_page(): void
    {
        $this->fakeCentral(['theme' => 'dark']);
        $this->actingAs($this->user());

        // Any authenticated GET runs the check (here: a guest-tolerant JSON route).
        $this->app['router']->get('/__probe', fn () => response()->json(['ok' => true]))->middleware('web');
        $this->getJson('/__probe')->assertOk();
        $this->assertSame(['theme' => 'dark'], session('solavel_display_preferences'));

        $this->getJson('/preferences/sync')->assertExactJson(['changed' => true, 'locale' => 'en', 'theme' => 'dark']);
        $this->getJson('/preferences/sync')->assertJson(['changed' => false]);
        Http::assertSentCount(1);
    }

    public function test_guests_are_never_synced(): void
    {
        Http::fake();

        $this->getJson('/preferences/sync')->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_an_open_page_language_header_does_not_overwrite_the_session_language(): void
    {
        $this->fakeCentral(['locale' => 'ar']);

        $this->actingAs($this->user())->withSession(['solastock_locale' => 'ar', 'account_display_preferences_checked_at' => time()])
            ->withHeaders(['X-Locale' => 'en'])->getJson('/preferences/sync')->assertOk();
        $this->assertSame('ar', session('solastock_locale'));

        $this->withHeaders(['X-Locale' => 'en'])->get('/preferences/sync?locale=en');
        $this->assertSame('en', session('solastock_locale'));
    }

    public function test_following_the_default_repairs_an_old_session_even_if_account_value_is_unchanged(): void
    {
        $this->fakeCentral(['locale' => 'ar', 'theme' => 'light']);
        $this->actingAs($this->user())->getJson('/preferences/sync')->assertOk();
        $this->getJson('/preferences/sync?locale=en')->assertOk();
        $this->getJson('/preferences/sync?follow_locale=1')->assertOk()->assertJson(['locale' => 'ar', 'changed' => true]);
        $this->assertSame('ar', session('solastock_locale'));
    }

    public function test_browser_language_pick_is_consumed_once_and_keeps_unrelated_query_parameters(): void
    {
        $this->fakeCentral(['locale' => 'ar']);
        $this->actingAs($this->user())->get('/?locale=en&search=paper')
            ->assertRedirect('/?search=paper');
        $this->assertSame('en', session('solastock_locale'));
    }

    public function test_current_language_can_be_saved_as_the_account_default(): void
    {
        $this->fakeCentral(['locale' => 'en']);
        $this->actingAs($this->user())->withSession(['solastock_locale' => 'ar'])
            ->get('/?locale=ar&remember=1')->assertRedirect('/');
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
            && $r['preferred_locale'] === 'ar' && $r['central_user_id'] === 42);
    }

    public function test_account_switch_reads_and_saves_both_states(): void
    {
        foreach ([true, false] as $enabled) {
            $this->fakeCentral(['locale' => 'ar', 'remember_language' => $enabled]);
            $this->actingAs($this->user())->getJson('/preferences/sync?language_default=1')
                ->assertOk()->assertExactJson(['remember_language' => $enabled]);
            $this->postJson('/preferences/theme', ['remember_language' => $enabled])->assertOk()->assertJson(['saved' => true]);
            Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && $r['remember_language'] === $enabled);
        }
    }

    public function test_switch_save_failure_is_reported_and_guests_cannot_change_it(): void
    {
        Http::fake(['central.test/*' => Http::response([], 503)]);
        $this->actingAs($this->user())->postJson('/preferences/theme', ['remember_language' => false])->assertStatus(502)->assertJson(['saved' => false]);
    }
}
