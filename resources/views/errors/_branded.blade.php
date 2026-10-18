{{--
    Unified branded Solavel error page. Self-contained: inline CSS + inline SVG,
    no Vite/Tailwind/CDN dependency, so it renders even when assets are missing.

    Include with:  @include('errors._branded', ['b' => [...]])
      status    int     HTTP status shown in the chip (e.g. 403)
      chip      string  chip label after the status (e.g. "Permission")
      icon      string  chip icon key (see $svg below)
      title     string  big heading
      line      string  one-line explanation
      info      ?array  ['title' => .., 'text' => .., 'rows' => [[label, value], ..]]
      actions   array   [['style' => red|blue|primary|ghost, 'icon' => .., 'label' => ..,
                          'subtitle' => .., 'url' => .., 'method' => GET|POST, 'target' => ..], ..]
      links     ?array  [['label' => .., 'url' => .., 'icon' => ..], ..]  (default: back + home)
      app_name / app_subtitle  override the brand line (defaults from lang solavel_errors.*)
      report    ?bool   show "report a problem" (default: status >= 500)
--}}
@php
    $t = static fn (string $key, array $replace = []): string => (string) trans('solavel_errors.'.$key, $replace);
    $b = (array) ($b ?? []);
    $locale = (string) app()->getLocale();
    $rtl = in_array(substr($locale, 0, 2), ['ar', 'ur', 'fa', 'he'], true);
    $status = (int) ($b['status'] ?? 500);
    $appName = (string) ($b['app_name'] ?? $t('app_name'));
    $appSubtitle = (string) ($b['app_subtitle'] ?? $t('app_subtitle'));
    $title = (string) ($b['title'] ?? $t('e500.title'));
    $line = (string) ($b['line'] ?? '');
    $chip = (string) ($b['chip'] ?? '');
    $info = $b['info'] ?? null;
    $actions = array_values(array_filter((array) ($b['actions'] ?? []), static fn ($a) => is_array($a) && ! empty($a['url'])));

    try { $currentUrl = url()->current(); } catch (\Throwable) { $currentUrl = '/'; }
    try { $backUrl = url()->previous(); } catch (\Throwable) { $backUrl = null; }
    $homeUrl = (string) ($b['home_url'] ?? url('/'));
    $links = $b['links'] ?? null;
    if ($links === null) {
        $links = [];
        // "Back" always uses browser history when available; falls back to the referrer, then home.
        $links[] = ['label' => $t('link.back'), 'url' => ($backUrl && $backUrl !== $currentUrl) ? $backUrl : $homeUrl, 'icon' => 'back', 'history' => true];
        // Skip the home link when an action button already goes there.
        $homeTaken = false;
        foreach ($actions as $a) { if (rtrim((string) $a['url'], '/') === rtrim($homeUrl, '/')) { $homeTaken = true; } }
        if (! $homeTaken) {
            $links[] = ['label' => $t('link.home'), 'url' => $homeUrl, 'icon' => 'home'];
        }
    }
    $report = (bool) ($b['report'] ?? $status >= 500);
    $supportEmail = (string) (config('mail.support_address') ?: 'info@solavel.com');
    $reportHref = 'mailto:'.$supportEmail.'?subject='.rawurlencode('['.$appName.'] '.$status.' · '.$title)
        .'&body='.rawurlencode("URL: {$currentUrl}\nTime: ".now()->toIso8601String()."\n\n");

    $svg = [
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/>',
        'swap' => '<path d="M7 7h13l-3-3M17 17H4l3 3"/>',
        'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/>',
        'pause' => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'hand' => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert' => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/>',
        'cloud' => '<path d="M7 18a5 5 0 1 1 .8-9.9A6 6 0 0 1 19 10a4 4 0 0 1-1 8H7z"/>',
        'home' => '<path d="M3 11 12 4l9 7M5 10v10h14V10"/>',
        'back' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'portal' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'bug' => '<path d="M8 8a4 4 0 0 1 8 0v1H8zM6 9h12v5a6 6 0 0 1-12 0zM12 9v11M3 13h3M18 13h3M4 7l2 2M20 7l-2 2M4 19l2-2M20 19l-2-2"/>',
    ];
    $icon = static function (?string $name, string $class = 'ic') use ($svg): string {
        $path = $svg[$name ?? ''] ?? $svg['info'];

        return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
    };
    $logo = asset('images/solavel-logo.png');
    $robot = asset('images/robot-error.png');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} · {{ $appName }}</title>
<style>
:root{--bg:#eef3f1;--card:#ffffff;--text:#0f1f1a;--muted:#5b6b66;--border:#dfe7e4;--info-bg:#f5f8f7;--link:#4b5b56;--link-h:#0f1f1a;
--g1:#059669;--g2:#065f46;--g3:#022c22;--red:#dc2626;--red-h:#b91c1c;--blue:#2563eb;--blue-h:#1d4ed8;--pri:#059669;--pri-h:#047857;--ghost:#f1f5f4;--ghost-h:#e5ece9;--ghost-t:#0f1f1a;--shadow:0 30px 80px rgba(2,44,34,.18);color-scheme:light}
@media (prefers-color-scheme:dark){:root{--bg:#050a08;--card:#0f1714;--text:#e8f1ee;--muted:#9fb1ab;--border:#22312c;--info-bg:#14201c;--link:#a7b8b2;--link-h:#ffffff;--ghost:#1a2622;--ghost-h:#22312c;--ghost-t:#e8f1ee;--shadow:0 30px 80px rgba(0,0,0,.6);color-scheme:dark}}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{min-height:100vh;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",Tahoma,system-ui,-apple-system,"Noto Sans Arabic",sans-serif;display:flex;align-items:center;justify-content:center;padding:16px;line-height:1.5}
.wrap{width:100%;max-width:960px}
.card{background:var(--card);border-radius:28px;box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;border:1px solid var(--border)}
.left{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--g1),var(--g2) 55%,var(--g3));color:#fff;padding:32px 28px;display:flex;flex-direction:column;justify-content:space-between;gap:24px}
.left .ring{position:absolute;border:1px solid rgba(255,255,255,.12);border-radius:50%}
.left .r1{width:160px;height:160px;top:36px;inset-inline-start:36px}.left .r2{width:120px;height:120px;bottom:70px;inset-inline-start:80px}
.brand{position:relative;display:flex;align-items:center;gap:14px;margin-bottom:28px}
.logo{width:52px;height:52px;border-radius:16px;background:rgba(255,255,255,.92);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 20px rgba(0,0,0,.18);flex-shrink:0}
.logo img{width:40px;height:40px;object-fit:contain}
.brand b{display:block;font-size:22px;letter-spacing:.06em;font-weight:700}
.brand small{display:block;font-size:13px;color:#d1fae5}
.chip{position:relative;display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:600;letter-spacing:.03em;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.22);padding:6px 12px;border-radius:999px;margin-bottom:14px}
.chip .ic{width:14px;height:14px;color:#a7f3d0}
h1{position:relative;margin:0 0 10px;font-size:28px;line-height:1.25;font-weight:700}
.line{position:relative;margin:0;font-size:16px;color:#ecfdf5}
.robot{position:relative;display:flex;justify-content:center}
.robot img{width:132px;height:auto;filter:drop-shadow(0 8px 20px rgba(255,255,255,.25));animation:float 3s ease-in-out infinite}
html[dir=ltr] .robot img{transform:scaleX(-1)}
@keyframes float{0%,100%{translate:0 0}50%{translate:0 -10px}}
@media (prefers-reduced-motion:reduce){.robot img{animation:none}}
.right{padding:28px 24px;display:flex;flex-direction:column;justify-content:center;gap:20px}
.info{border:1px solid var(--border);background:var(--info-bg);border-radius:18px;padding:16px}
.info .k{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.info .v{font-size:15px;font-weight:600;margin-top:4px}
.info .t{font-size:14px;color:var(--muted);margin-top:4px}
.info .row{font-size:13px;color:var(--muted);margin-top:8px}.info .row span{font-weight:600;color:var(--text)}
.acts{display:flex;flex-direction:column;gap:12px}
.acts form{margin:0}
.act{width:100%;display:flex;align-items:center;gap:12px;padding:13px 16px;border-radius:16px;color:#fff;text-decoration:none;border:0;cursor:pointer;font:inherit;text-align:start;transition:background .15s,transform .15s}
.act:hover{transform:translateY(-1px)}.act:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
.act .bx{width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.act .bx .ic{width:20px;height:20px}
.act .tx{flex:1;min-width:0}.act .tx b{display:block;font-size:14px}.act .tx small{display:block;font-size:12px;opacity:.85}
.act .go{width:18px;height:18px;opacity:.8}
html[dir=rtl] .act .go,html[dir=rtl] .lk .ic-back{transform:scaleX(-1)}
.act.red{background:var(--red);box-shadow:0 10px 24px rgba(220,38,38,.22)}.act.red:hover{background:var(--red-h)}
.act.blue{background:var(--blue);box-shadow:0 10px 24px rgba(37,99,235,.22)}.act.blue:hover{background:var(--blue-h)}
.act.primary{background:var(--pri);box-shadow:0 10px 24px rgba(5,150,105,.22)}.act.primary:hover{background:var(--pri-h)}
.act.ghost{background:var(--ghost);color:var(--ghost-t);border:1px solid var(--border)}.act.ghost:hover{background:var(--ghost-h)}.act.ghost .bx{background:var(--card)}
.links{display:flex;flex-wrap:wrap;gap:8px 20px;justify-content:center;font-size:14px}
.lk{display:inline-flex;align-items:center;gap:6px;color:var(--link);text-decoration:none}.lk:hover{color:var(--link-h);text-decoration:underline}
.lk .ic{width:15px;height:15px}
.report{text-align:center;font-size:13px;color:var(--muted)}.report a{color:var(--link)}
@media (min-width:860px){.card{flex-direction:row;min-height:520px}.left,.right{width:50%}.left{padding:52px 48px}.right{padding:44px 40px}h1{font-size:32px}.robot img{width:160px}}
@media (max-width:420px){h1{font-size:24px}.left{padding:26px 20px}.right{padding:22px 16px}.robot img{width:104px}}
</style>
</head>
<body>
<main class="wrap">
  <div class="card">
    <section class="left">
      <span class="ring r1"></span><span class="ring r2"></span>
      <div>
        <div class="brand">
          <div class="logo"><img src="{{ $logo }}" alt="Solavel"></div>
          <div><b>SOLAVEL</b><small>{{ $appSubtitle !== '' ? $appSubtitle : $appName }}</small></div>
        </div>
        <div class="chip">{!! $icon($b['icon'] ?? 'info') !!}<span>{{ $status }}@if($chip !== '') · {{ $chip }}@endif</span></div>
        <h1>{{ $title }}</h1>
        @if($line !== '')<p class="line">{{ $line }}</p>@endif
      </div>
      <div class="robot"><img src="{{ $robot }}" alt=""></div>
    </section>

    <section class="right">
      @if(is_array($info) && (($info['title'] ?? '') !== '' || ($info['text'] ?? '') !== '' || ! empty($info['rows'])))
        <div class="info">
          @if(($info['label'] ?? '') !== '')<div class="k">{{ $info['label'] }}</div>@endif
          @if(($info['title'] ?? '') !== '')<div class="v">{{ $info['title'] }}</div>@endif
          @if(($info['text'] ?? '') !== '')<div class="t">{{ $info['text'] }}</div>@endif
          @foreach((array) ($info['rows'] ?? []) as $row)
            @if(is_array($row) && ($row[1] ?? '') !== '')<div class="row">{{ $row[0] }} <span>{{ $row[1] }}</span></div>@endif
          @endforeach
        </div>
      @endif

      @if($actions !== [])
        <div class="acts">
          @foreach($actions as $a)
            @php
                $style = in_array($a['style'] ?? '', ['red', 'blue', 'primary', 'ghost'], true) ? $a['style'] : 'primary';
                $method = strtoupper((string) ($a['method'] ?? 'GET'));
                $inner = '<span class="bx">'.$icon($a['icon'] ?? 'arrow').'</span><span class="tx"><b>'.e($a['label'] ?? '').'</b>'
                    .(($a['subtitle'] ?? '') !== '' ? '<small>'.e($a['subtitle']).'</small>' : '').'</span>'.$icon('arrow', 'go');
            @endphp
            @if($method === 'GET')
              <a class="act {{ $style }}" href="{{ $a['url'] }}" @if(! empty($a['target'])) target="{{ $a['target'] }}" rel="noopener" @endif>{!! $inner !!}</a>
            @else
              <form method="POST" action="{{ $a['url'] }}">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                @if($method !== 'POST')<input type="hidden" name="_method" value="{{ $method }}">@endif
                <button type="submit" class="act {{ $style }}">{!! $inner !!}</button>
              </form>
            @endif
          @endforeach
        </div>
      @endif

      @if($links !== [])
        <nav class="links">
          @foreach($links as $l)
            <a class="lk" href="{{ $l['url'] }}"@if(! empty($l['history'])) onclick="if (history.length > 1) { history.back(); return false; }"@endif>{!! $icon($l['icon'] ?? 'arrow', 'ic ic-'.($l['icon'] ?? 'arrow')) !!}{{ $l['label'] }}</a>
          @endforeach
        </nav>
      @endif

      @if($report)
        <div class="report">{{ $t('report.prompt') }} <a href="{{ $reportHref }}">{{ $t('report.link') }}</a></div>
      @endif
    </section>
  </div>
</main>
</body>
</html>
