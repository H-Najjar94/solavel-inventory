@props([
    // success | info | warning | error — picks the badge colour and icon.
    'tone' => 'info',
    'title',
    'eyebrow' => null,
    // Optional image (e.g. the organization's logo). Shown in place of the
    // icon, with the tone icon as a small badge on its corner.
    'logo' => null,
])
@php
    $icons = [
        'success' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 12.5l4.2 4.2L19 7"/>',
        'info' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7.5l8 5.5 8-5.5M5 6h14a1 1 0 011 1v10a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1z"/>',
        'warning' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v5m0 3.5h.01M10.3 3.9L2.4 17.6A2 2 0 004.1 20.6h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
        'error' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3.5l7 3v5.2c0 4.3-2.9 7.6-7 8.8-4.1-1.2-7-4.5-7-8.8V6.5l7-3zM9.5 9.5l5 5m0-5l-5 5"/>',
    ];
@endphp
@once
<style>
    /* Status panel: the sign-in card's language for transactional pages. */
    .st { text-align: center; }
    .st-badge { position: relative; width: 68px; height: 68px; margin: 0 auto 18px; border-radius: 22px; display: grid; place-items: center;
        background: var(--st-bg); color: var(--st-ink); box-shadow: 0 0 0 8px var(--st-ring); }
    .st-badge svg { width: 30px; height: 30px; }
    .st-badge::after { content: ""; position: absolute; inset: -14px; border-radius: 30px; border: 1.5px dashed var(--st-ring-2); opacity: .9; }
    .st--success { --st-bg: #d1fae5; --st-ink: #047857; --st-ring: #ecfdf5; --st-ring-2: #a7f3d0; }
    .st--info { --st-bg: color-mix(in srgb, var(--lp-accent) 16%, #fff); --st-ink: var(--lp-strong); --st-ring: color-mix(in srgb, var(--lp-accent) 7%, #fff); --st-ring-2: color-mix(in srgb, var(--lp-accent) 30%, #fff); }
    .st--warning { --st-bg: #fef3c7; --st-ink: #b45309; --st-ring: #fffbeb; --st-ring-2: #fde68a; }
    .st--error { --st-bg: #fee2e2; --st-ink: #b91c1c; --st-ring: #fef2f2; --st-ring-2: #fecaca; }
    .st-logo { position: relative; width: 76px; height: 76px; margin: 0 auto 20px; padding: 10px; border-radius: 22px; background: #fff;
        border: 1px solid var(--lp-line); box-shadow: 0 0 0 8px var(--st-ring), 0 10px 24px -14px rgba(15,23,42,.35); }
    .st-logo img { display: block; width: 100%; height: 100%; object-fit: contain; }
    .st-logo-check { position: absolute; inset-inline-end: -9px; bottom: -9px; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center;
        background: var(--st-ink); color: #fff; box-shadow: 0 0 0 3px #fff; }
    .st-logo-check svg { width: 16px; height: 16px; stroke-width: 3; }
    /* The icon badge is the fallback: hidden while a logo shows, used if it fails to load. */
    .st-badge--fallback { display: none; }
    .st-logo.is-broken { display: none; }
    .st-logo.is-broken + .st-badge--fallback { display: grid; }
    .st-eyebrow { margin: 0 0 6px; color: var(--st-ink); font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
    .st h2 { margin: 0; }
    .st-msg { margin: 10px auto 0; max-width: 34ch; color: var(--lp-muted); font-size: 15.5px; line-height: 1.55; }
    .st-msg strong { color: var(--lp-ink); font-weight: 600; }
    .st-msg + .st-msg { margin-top: 8px; }
    .st-details { margin: 22px 0 0; padding: 4px 0; border: 1px solid var(--lp-line); border-radius: 16px; background: #fbfdfc; text-align: start; }
    .st-row { display: flex; align-items: center; gap: 12px; padding: 11px 16px; }
    .st-row + .st-row { border-top: 1px solid var(--lp-line); }
    .st-row img { width: 26px; height: 30px; object-fit: contain; flex: none; }
    .st-row .st-dot { width: 30px; height: 30px; border-radius: 10px; flex: none; display: grid; place-items: center; background: #f1f5f9; color: var(--lp-muted); }
    .st-row .st-dot svg { width: 16px; height: 16px; }
    .st-row b { display: block; color: var(--lp-ink); font-size: 15px; font-weight: 600; }
    .st-row small { display: block; color: var(--lp-muted); font-size: 13px; }
    .st-row .st-pill { margin-inline-start: auto; padding: 4px 10px; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .st-row .st-pill.is-muted { background: #f1f5f9; color: var(--lp-muted); }
    .st-row .st-mono { direction: ltr; unicode-bidi: isolate; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13.5px; color: var(--lp-ink); word-break: break-all; }
    .st-actions { display: grid; gap: 10px; margin-top: 24px; }
    .st-actions form { margin: 0; }
    .st-actions .lp-btn { text-decoration: none; }
    .st-ghost { display: flex; width: 100%; height: 50px; align-items: center; justify-content: center; gap: 8px; border: 1.5px solid var(--lp-line);
        border-radius: 15px; background: #fff; color: var(--lp-ink); font: inherit; font-size: 15.5px; font-weight: 600; text-decoration: none; cursor: pointer; transition: border-color .15s, background .15s; }
    .st-ghost:hover { border-color: color-mix(in srgb, var(--lp-accent) 45%, var(--lp-line)); background: color-mix(in srgb, var(--lp-accent) 5%, #fff); }
    .st-links { display: flex; justify-content: center; gap: 18px; flex-wrap: wrap; margin-top: 18px; font-size: 14px; }
    .st-links form { margin: 0; display: inline; }
    .st-links button { border: 0; background: none; padding: 0; font: inherit; color: var(--lp-muted); cursor: pointer; }
    .st-links button:hover, .st-links a:hover { color: var(--lp-ink); text-decoration: underline; }
    .st-links a { color: var(--lp-muted); text-decoration: none; }
    .st-foot { margin: 22px 0 0; padding-top: 16px; border-top: 1px dashed var(--lp-line); color: var(--lp-muted); font-size: 13px; line-height: 1.5; }
    .st-flash { margin: 18px 0 0; padding: 11px 14px; border-radius: 14px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; font-size: 14px; text-align: start; }
</style>
@endonce
<div {{ $attributes->class(['st', 'st--'.$tone]) }}>
    @if($logo)
        <div class="st-logo" aria-hidden="true">
            <img src="{{ $logo }}" alt="" onerror="this.closest('.st-logo').classList.add('is-broken')">
            <span class="st-logo-check"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$tone] ?? $icons['info'] !!}</svg></span>
        </div>
    @endif
    <div class="st-badge{{ $logo ? ' st-badge--fallback' : '' }}" aria-hidden="true">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$tone] ?? $icons['info'] !!}</svg>
    </div>
    @if($eyebrow)<p class="st-eyebrow">{{ $eyebrow }}</p>@endif
    <h2 id="lp-title">{{ $title }}</h2>
    {{ $slot }}
    @isset($details)
        <div class="st-details">{{ $details }}</div>
    @endisset
    @isset($actions)
        <div class="st-actions">{{ $actions }}</div>
    @endisset
    @isset($links)
        <div class="st-links">{{ $links }}</div>
    @endisset
    @isset($foot)
        <p class="st-foot">{{ $foot }}</p>
    @endisset
</div>
