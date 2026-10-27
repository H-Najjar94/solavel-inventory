{{--
    Focused layout for status pages (invitation, verification, access).
    One centred card, a short handwritten line above it, nothing else.
    Sections: title, note (handwritten line), lp-app (product theme key), panel.
--}}
@php
    $footerCopy = [
        'en'=>['One account, every app','Need help?'], 'ar'=>['حساب واحد لكل التطبيقات','بحاجة إلى مساعدة؟'],
        'fr'=>['Un compte, toutes les applications','Besoin d’aide ?'], 'de'=>['Ein Konto für alle Apps','Brauchen Sie Hilfe?'],
        'es'=>['Una cuenta para todas las aplicaciones','¿Necesita ayuda?'], 'it'=>['Un account per tutte le app','Serve aiuto?'],
        'pt'=>['Uma conta para todas as aplicações','Precisa de ajuda?'], 'tr'=>['Tek hesap, tüm uygulamalar','Yardıma mı ihtiyacınız var?'],
        'ru'=>['Один аккаунт для всех приложений','Нужна помощь?'], 'ur'=>['ایک اکاؤنٹ، تمام ایپس','مدد درکار ہے؟'],
        'zh'=>['一个账号，所有应用','需要帮助？'],
    ][app()->getLocale()] ?? ['One account, every app','Need help?'];
    $isAr = app()->getLocale() === 'ar';
    $tr = static fn (string $en, string $ar): string => app()->getLocale() === 'ar' ? $ar : __($en);
    $destinations = ['solavel'=>['theme'=>['accent'=>'#059669','solid'=>'#05875f','strong'=>'#047250','button'=>'#05875f','buttonInk'=>'#ffffff']]];
    $appKey = trim($__env->yieldContent('lp-app')) ?: 'solavel';
    $dest = $destinations[$appKey] ?? $destinations['solavel'];
    $theme = $dest['theme'] ?? $destinations['solavel']['theme'];
    $localeSwitchUrl = request()->fullUrlWithQuery(['lang' => $isAr ? 'en' : 'ar']);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ (in_array(app()->getLocale(), ['ar','ur'], true) ? 'rtl' : 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Solavel')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset($appKey === 'solavel' ? 'images/favicon-green.svg' : ($dest['logo'] ?? 'images/favicon-green.svg')) }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=outfit:400,500,600,700,800|alexandria:400,500,600,700|caveat:600,700|aref-ruqaa:400&display=swap" rel="stylesheet">
    <style>
        :root {
            --lp-accent: {{ $theme['accent'] }}; --lp-solid: {{ $theme['solid'] }}; --lp-strong: {{ $theme['strong'] }};
            --lp-button: {{ $theme['button'] }}; --lp-button-ink: {{ $theme['buttonInk'] }};
            --lp-green: #059669; --lp-ink: #0b1220; --lp-text: #334155; --lp-muted: #64748b; --lp-line: #e2e8f0;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; color: var(--lp-text); background: #f7faf9;
            font-family: 'Outfit', 'Alexandria', system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; -webkit-font-smoothing: antialiased; }
        body::before { content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(38rem 26rem at 50% 0%, color-mix(in srgb, var(--lp-accent) 12%, transparent), transparent 70%),
                radial-gradient(circle at 1px 1px, color-mix(in srgb, var(--lp-accent) 22%, transparent) 1px, transparent 1.4px) 0 0 / 24px 24px;
            -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 40%, #000 30%, transparent 80%); mask-image: radial-gradient(ellipse 70% 60% at 50% 40%, #000 30%, transparent 80%); }
        a { color: inherit; }
        .sl-top { display: flex; align-items: center; justify-content: space-between; width: 100%; max-width: 1080px; margin: 0 auto; padding: 22px 24px 0; }
        .sl-brand { display: inline-flex; align-items: center; gap: 9px; color: var(--lp-green); font-size: 22px; font-weight: 800; letter-spacing: -.02em; text-decoration: none; }
        .sl-brand img { width: 22px; height: 28px; object-fit: contain; }
        .sl-lang { display: inline-flex; align-items: center; gap: 7px; padding: 8px 11px; border-radius: 11px; color: var(--lp-text); font-size: 14px; font-weight: 500; text-decoration: none; }
        .sl-lang:hover { background: #fff; }
        .sl-lang svg { width: 17px; height: 17px; }
        .sl-main { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 28px 20px 40px; }
        .sl-col { width: 100%; max-width: 468px; }
        .sl-note { position: relative; margin: 0 0 14px; padding-inline-start: 6px; color: var(--lp-strong); font-family: 'Caveat', 'Segoe Script', cursive;
            font-size: 27px; font-weight: 700; line-height: 1.1; transform: rotate(-2.5deg); transform-origin: 0 100%; }
        [dir="rtl"] .sl-note { font-family: 'Aref Ruqaa', serif; font-size: 22px; font-weight: 400; transform: rotate(2.5deg); transform-origin: 100% 100%; }
        .sl-note svg { display: inline-block; width: 38px; height: 30px; margin-inline-start: 6px; vertical-align: -14px; color: color-mix(in srgb, var(--lp-accent) 70%, #fff); }
        [dir="rtl"] .sl-note svg { transform: scaleX(-1); }
        .sl-card { position: relative; padding: 34px 32px 28px; border: 1px solid var(--lp-line); border-radius: 24px; background: #fff;
            box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 28px 60px -32px color-mix(in srgb, var(--lp-accent) 45%, rgba(15,23,42,.25)); }
        .sl-card h2 { margin: 0; color: var(--lp-ink); font-size: 27px; font-weight: 800; letter-spacing: -.03em; line-height: 1.2; text-align: center; }
        .lp-btn { display: flex; width: 100%; height: 52px; align-items: center; justify-content: center; gap: 10px; border: 0; border-radius: 14px;
            background: linear-gradient(180deg, color-mix(in srgb, var(--lp-button) 88%, #fff), var(--lp-button)); color: var(--lp-button-ink);
            font: inherit; font-size: 16.5px; font-weight: 600; text-decoration: none; cursor: pointer;
            box-shadow: 0 12px 24px -12px color-mix(in srgb, var(--lp-accent) 70%, transparent); transition: transform .15s, filter .15s; }
        .lp-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
        .lp-btn svg { width: 19px; height: 19px; }
        [dir="rtl"] .lp-btn .lp-arrow { transform: scaleX(-1); }
        .sl-foot { margin: 18px 0 0; color: var(--lp-muted); font-size: 13px; text-align: center; }
        .sl-foot a { color: var(--lp-strong); font-weight: 600; text-decoration: none; }
        .sl-foot span { margin-inline: 6px; opacity: .5; }
        @media (max-width: 520px) { .sl-card { padding: 28px 20px 22px; border-radius: 20px; } .sl-card h2 { font-size: 24px; } .sl-note { font-size: 24px; } }
    </style>
    @stack('styles')

</head>
<body data-app="{{ $appKey }}">
    <header class="sl-top">
        <a class="sl-brand" href="{{ rtrim((string) config('sso.central_app_url', 'https://solavel.com'), '/') }}/portal"><img src="{{ rtrim((string) config('sso.central_app_url', 'https://solavel.com'), '/').'/images/brand/solavel.svg' }}" alt="" aria-hidden="true">Solavel</a>
    
        <details class="sl-lang" style="position:relative">
            <summary style="cursor:pointer">{{ ['en'=>'English','ar'=>'العربية','fr'=>'Français','de'=>'Deutsch','es'=>'Español','it'=>'Italiano','pt'=>'Português','tr'=>'Türkçe','ru'=>'Русский','ur'=>'اردو','zh'=>'中文'][app()->getLocale()] ?? 'English' }}</summary>
            <nav style="position:absolute;inset-inline-end:0;top:100%;z-index:20;min-width:180px;max-height:60vh;overflow:auto;background:white;border:1px solid #e2e8f0;border-radius:14px;padding:8px;box-shadow:0 12px 32px #10203020">
                @foreach(['en'=>'English','ar'=>'العربية','fr'=>'Français','de'=>'Deutsch','es'=>'Español','it'=>'Italiano','pt'=>'Português','tr'=>'Türkçe','ru'=>'Русский','ur'=>'اردو','zh'=>'中文'] as $languageCode=>$languageLabel)
                    <a style="display:block;padding:6px 12px;text-decoration:none" href="{{ request()->fullUrlWithQuery(['lang'=>$languageCode]) }}" lang="{{ $languageCode }}">{{ $languageLabel }}</a>
                @endforeach
            </nav>
        </details>
    </header>

    <main class="sl-main">
        <div class="sl-col">
            @hasSection('note')
                <p class="sl-note" dir="{{ (in_array(app()->getLocale(), ['ar','ur'], true) ? 'rtl' : 'ltr') }}">@yield('note')
                    <svg viewBox="0 0 44 34" fill="none" aria-hidden="true"><path d="M3 4c12 1 24 6 30 20" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><path d="M26 22l7 3 3-7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </p>
            @endif
            <section class="sl-card" aria-labelledby="lp-title">
                @yield('panel')
            </section>
            <p class="sl-foot">{{ $footerCopy[0] }}<span>·</span><a href="mailto:info@solavel.com">{{ $footerCopy[1] }}</a></p>
        </div>
    </main>

</body>
</html>
