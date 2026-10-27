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

    $welcome = [
        'en'=>'Let’s help you continue', 'ar'=>'لنساعدك على المتابعة', 'fr'=>'Continuons ensemble',
        'de'=>'Wir helfen Ihnen weiter', 'es'=>'Le ayudamos a continuar', 'it'=>'Ti aiutiamo a continuare',
        'pt'=>'Vamos ajudar a continuar', 'tr'=>'Devam etmenize yardımcı olalım', 'ru'=>'Поможем вам продолжить',
        'ur'=>'آئیے آگے بڑھنے میں آپ کی مدد کریں', 'zh'=>'让我们帮您继续',
    ][$locale] ?? 'Let’s help you continue';
    $themeKey = $appKey ?? (['SolaHR'=>'hr','SolaCount'=>'finance','SolaStock'=>'inventory','SolaProjects'=>'projects'][$appSubtitle] ?? 'solavel');
@endphp
@extends('auth.status-layout', ['statusPage'=>true])
@section('title', $title.' · '.$appName)
@section('note', $welcome)
@section('lp-app', $themeKey)
@push('styles')
<style>
.er-org-row{display:flex;align-items:center;gap:14px;padding:14px;text-align:start;text-decoration:none;background:#fff;border:1px solid #e3e9ef;border-radius:16px;color:#0b1220;transition:background .15s,border-color .15s,box-shadow .15s}
.er-org-row:hover{background:#f7fbf9;border-color:color-mix(in srgb,var(--lp-accent) 45%,#e3e9ef);box-shadow:0 5px 16px #10203008}.er-org-row:focus-visible{outline:3px solid var(--lp-accent);outline-offset:3px}
.er-org-logo{display:grid;place-items:center;flex:none;width:48px;height:48px;border:1px solid #e8edf2;border-radius:13px;background:#f8faf9;overflow:hidden;color:var(--lp-strong);font-size:16px;font-weight:700}
.er-org-logo img{width:100%;height:100%;padding:6px;object-fit:contain;background:white}.er-org-logo.has-logo .er-org-initials{display:none}
.er-org-copy{display:grid;gap:4px;flex:1;min-width:0}.er-org-copy b{font-size:15px;line-height:1.5;font-weight:650;overflow-wrap:anywhere}.er-org-copy small{font-size:12px;font-weight:400;color:#7a8798}
.er-org-arrow{width:30px;height:30px;flex:none;border-radius:50%;padding:7px;background:#f2f7f4;color:var(--lp-strong)}[dir=rtl] .er-org-arrow{transform:scaleX(-1)}
@media(max-width:380px){.er-org-row{gap:10px;padding:12px}.er-org-logo{width:42px;height:42px}.er-org-copy b{font-size:14px}}
</style>
@endpush
@section('panel')
    <x-auth.status :tone="$status >= 500 ? 'error' : ($status === 403 ? 'warning' : 'info')" :eyebrow="trim($status.' · '.$chip, ' ·')" :title="$title">
        @if($line !== '')<p class="st-msg">{{ $line }}</p>@endif
        @if(is_array($info) && (!empty($info['text']) || !empty($info['rows']) || !empty($info['title'])))
            <div class="st-flash">
                @if(!empty($info['label']))<strong>{{ $info['label'] }}</strong>@endif
                @if(!empty($info['title']))<p>{{ $info['title'] }}</p>@endif
                @if(!empty($info['text']))<p style="margin:0">{{ $info['text'] }}</p>@endif
                @foreach((array)($info['rows'] ?? []) as $row)
                    @if(is_array($row) && ($row[1] ?? '') !== '')<div class="st-row"><small>{{ $row[0] }}</small><b>{{ $row[1] }}</b></div>@endif
                @endforeach
            </div>
        @endif
        <x-slot:actions>
            @foreach($actions as $a)
                @php
                    $method = strtoupper((string) ($a['method'] ?? 'GET'));
                    $buttonClass = ($a['style'] ?? '') === 'primary' || ($a['style'] ?? '') === 'blue' ? 'lp-btn' : 'st-ghost';
                @endphp
                @if($method === 'GET' && ($a['style'] ?? '') === 'organization')
                    <a class="er-org-row" href="{{ $a['url'] }}">
                        <span class="er-org-logo {{ !empty($a['logo_url']) ? 'has-logo' : '' }}" aria-hidden="true">
                            @if(!empty($a['logo_url']))<img src="{{ $a['logo_url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentElement.classList.remove('has-logo');this.remove();">@endif
                            <span class="er-org-initials">{{ mb_strtoupper(mb_substr(trim((string)($a['label'] ?? '')), 0, 2)) }}</span>
                        </span>
                        <span class="er-org-copy"><b dir="auto">{{ $a['label'] ?? '' }}</b><small>{{ $a['subtitle'] ?? '' }}</small></span>
                        <svg class="er-org-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    </a>
                @elseif($method === 'GET')
                    <a class="{{ $buttonClass }}" style="height:auto;min-height:52px;padding:12px 16px;flex-wrap:wrap;overflow-wrap:anywhere" href="{{ $a['url'] }}" @if(!empty($a['target'])) target="{{ $a['target'] }}" rel="noopener" @endif>
                        <span>{{ $a['label'] ?? '' }}</span>
                        @if(!empty($a['subtitle']))<small style="width:100%;font-size:11px;font-weight:400">{{ $a['subtitle'] }}</small>@endif
                    </a>
                @else
                    <form method="POST" action="{{ $a['url'] }}">
                        @csrf
                        @if($method !== 'POST')<input type="hidden" name="_method" value="{{ $method }}">@endif
                        <button type="submit" class="{{ $buttonClass }}">{{ $a['label'] ?? '' }}</button>
                    </form>
                @endif
            @endforeach
        </x-slot:actions>
        @if($links !== [])
            <x-slot:links>
                @foreach($links as $l)<a href="{{ $l['url'] }}" @if(!empty($l['history'])) onclick="if(history.length > 1){history.back();return false;}" @endif>{{ $l['label'] }}</a>@endforeach
            </x-slot:links>
        @endif
        @if($report)
            <x-slot:foot>{{ $t('report.prompt') }} <a href="{{ $reportHref }}">{{ $t('report.link') }}</a></x-slot:foot>
        @endif
    </x-auth.status>
@endsection
