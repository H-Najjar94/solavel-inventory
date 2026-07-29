<?php

namespace Tests\Feature\Locale;

use App\Http\Middleware\AuthenticateFromInventoryHandoff;
use App\Http\Middleware\BounceToParentForSso;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticatedRtlHarnessTest extends TestCase
{
    private function authenticate(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->withoutMiddleware([
            AuthenticateFromInventoryHandoff::class,
            BounceToParentForSso::class,
        ]);

        DB::connection('mysql')->table('users')->updateOrInsert(
            ['email' => 'solastock-rtl-harness@example.test'],
            [
                'name' => 'SolaStock RTL Harness',
                'phone' => '0000000000',
                'identification_number' => 'RTL-HARNESS',
                'address' => 'Reserved QA',
                'password' => Hash::make('password'),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $user = User::query()->where('email', 'solastock-rtl-harness@example.test')->firstOrFail();
        $this->actingAs($user);
    }

    #[Test]
    public function authenticated_shell_can_be_switched_to_arabic_rtl(): void
    {
        $this->authenticate();

        $this->get('http://localhost/dashboard?locale=ar')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSessionHas('solastock_locale', 'ar');
    }

    #[Test]
    public function authenticated_shell_can_be_switched_back_to_english_ltr(): void
    {
        $this->authenticate();

        $this->withSession(['solastock_locale' => 'ar'])
            ->get('http://localhost/dashboard?locale=en')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSessionHas('solastock_locale', 'en');
    }

    #[Test]
    public function api_locale_header_localizes_recoverable_errors_and_persists(): void
    {
        Route::middleware('web')->get('/_test/locale-message', fn () => response()->json([
            'message' => __('inventory.common.resource_not_found'),
        ]));

        $this->withHeader('X-Locale', 'ar')
            ->getJson('/_test/locale-message')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'المورد المطلوب غير موجود.')
            ->assertSessionHas('solastock_locale', 'ar');
    }

    #[Test]
    public function api_locale_uses_english_fallback_for_an_unsupported_locale(): void
    {
        Route::middleware('web')->get('/_test/locale-fallback', fn () => response()->json([
            'message' => __('inventory.common.resource_not_found'),
        ]));

        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->getJson('/_test/locale-fallback')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Resource not found.')
            ->assertSessionHas('solastock_locale', 'en');
    }
}
