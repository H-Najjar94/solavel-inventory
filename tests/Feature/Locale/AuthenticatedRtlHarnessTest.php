<?php

namespace Tests\Feature\Locale;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticatedRtlHarnessTest extends TestCase
{
    #[Test]
    public function authenticated_shell_can_be_switched_to_arabic_rtl(): void
    {
        $this->get('/dashboard?locale=ar')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSessionHas('solastock_locale', 'ar');
    }

    #[Test]
    public function authenticated_shell_can_be_switched_back_to_english_ltr(): void
    {
        $this->withSession(['solastock_locale' => 'ar'])
            ->get('/dashboard?locale=en')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSessionHas('solastock_locale', 'en');
    }
}
