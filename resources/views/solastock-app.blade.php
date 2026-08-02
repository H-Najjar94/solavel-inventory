<!DOCTYPE html>
@php
    $requestedLocale = request()->query('locale');
    $allowedLocales = ['en', 'ar'];
    if (in_array($requestedLocale, $allowedLocales, true)) {
        session(['solastock_locale' => $requestedLocale]);
    }
    $locale = session('solastock_locale', app()->getLocale());
    if (! in_array($locale, $allowedLocales, true)) {
        $locale = 'en';
    }
    app()->setLocale($locale);
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
@endphp
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>SolaStock — Inventory Command</title>

    <link rel="icon" href="{{ asset('imgs/favicon-solastock.svg') }}" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />
    {{-- Font Awesome (sidebar/nav icons, matching the SolaBooks app) --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    {{-- No-flash theme boot (same key the React theme store uses). --}}
    <script>
        (function () {
            var t = localStorage.getItem("solastock_theme");
            if (!t) t = (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) ? "dark" : "light";
            document.documentElement.setAttribute("data-theme", t);
            window.SOLASTOCK_LOCALE = @json(['locale' => $locale, 'dir' => $dir]);
            window.SOLASTOCK_BASE_PATH = @json(app()->environment('staging') ? '' : '/inventory');
        })();
    </script>

    {{-- Vite React app (no CDN React, no in-browser Babel, no window.DB). --}}
    @viteReactRefresh
    @vite(['resources/js/solastock/styles/solastock.css', 'resources/js/solastock/app.jsx'])
</head>
<body>
    <div id="solastock-root"></div>
</body>
</html>
