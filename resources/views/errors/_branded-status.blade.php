{{--
    Generic status page (401/404/405/413/419/429/500/502/503/504) on the branded layout.
    Include with @include('errors._branded-status', ['code' => 404]).
    Honours legacy overrides: $errorMessage (line), $errorNote (info text), $variant/$reason
    ('access_check' / 'temporarily_unavailable' turns a 503 into the access-check page).
--}}
@php
    $t = static fn (string $key, array $replace = []): string => (string) trans('solavel_errors.'.$key, $replace);
    $code = (int) ($code ?? 500);
    $has = static fn (string $name): bool => \Illuminate\Support\Facades\Route::has($name);
    $centralBase = rtrim((string) (config('sso.central_app_url') ?: 'https://solavel.com'), '/');
    try { $portalUrl = $portalUrl ?? ($has('portal.home') ? route('portal.home') : $centralBase.'/portal'); } catch (\Throwable) { $portalUrl = $centralBase.'/portal'; }
    try { $loginUrl = $has('login') ? route('login') : $portalUrl; } catch (\Throwable) { $loginUrl = $portalUrl; }
    try { $currentUrl = url()->current(); $backUrl = url()->previous(); } catch (\Throwable) { $currentUrl = url('/'); $backUrl = url('/'); }
    $homeUrl = url('/');
    $sameAsCurrent = ! $backUrl || $backUrl === $currentUrl;

    $retry = ['style' => 'primary', 'icon' => 'refresh', 'label' => $t('action.retry'), 'subtitle' => $t('action.retry_sub'), 'url' => request()->isMethod('GET') ? request()->fullUrl() : ($sameAsCurrent ? $homeUrl : $backUrl)];
    $goBack = ['style' => 'primary', 'icon' => 'back', 'label' => $t('action.go_back'), 'subtitle' => $t('action.go_back_sub'), 'url' => $sameAsCurrent ? $homeUrl : $backUrl];
    $dashboard = ['style' => 'blue', 'icon' => 'home', 'label' => $t('action.dashboard'), 'subtitle' => $t('action.dashboard_sub'), 'url' => $homeUrl];

    $variantKey = strtolower((string) ($variant ?? $reason ?? ''));
    $isAccessCheck = $code === 503 && in_array($variantKey, ['access_check', 'temporarily_unavailable'], true);

    [$key, $icon, $chipKey, $actions] = match (true) {
        $code === 401 => ['e401', 'login', '401', [['style' => 'primary', 'icon' => 'login', 'label' => $t('action.sign_in'), 'subtitle' => $t('action.sign_in_sub'), 'url' => $loginUrl]]],
        $code === 404 => ['e404', 'search', '404', [array_replace($dashboard, ['style' => 'primary'])]],
        $code === 405 => ['e405', 'hand', '405', [$goBack]],
        $code === 413 => ['e413', 'upload', '413', [$goBack]],
        $code === 419 => ['e419', 'refresh', '419', [['style' => 'primary', 'icon' => 'refresh', 'label' => $t('action.refresh'), 'subtitle' => $t('action.refresh_sub'), 'url' => $sameAsCurrent ? $homeUrl : $backUrl]]],
        $code === 429 => ['e429', 'clock', '429', [$retry]],
        $code === 502, $code === 504 => ['e502', 'cloud', (string) $code, [$retry, $dashboard]],
        $isAccessCheck => ['access_check', 'clock', 'access_check', [$retry, ['style' => 'blue', 'icon' => 'portal', 'label' => $t('action.portal'), 'subtitle' => $t('action.portal_sub'), 'url' => $portalUrl]]],
        $code === 503 => ['e503', 'tool', '503', [$retry]],
        default => ['e500', 'bug', '500', [$retry, $dashboard]],
    };

    $legacyNote = isset($errorNote) && is_string($errorNote) && $errorNote !== '' ? $errorNote : null;
    $branded = [
        'status' => in_array($code, [401, 404, 405, 413, 419, 429, 500, 502, 503, 504], true) ? $code : 500,
        'icon' => $icon,
        'chip' => $t('chip.'.$chipKey),
        'title' => $t($key.'.title'),
        'line' => isset($errorMessage) && is_string($errorMessage) && $errorMessage !== '' ? $errorMessage : $t($key.'.line'),
        'info' => $legacyNote ? ['label' => $t('info.details'), 'text' => $legacyNote] : null,
        'actions' => $actions,
    ];
@endphp
@include('errors._branded', ['b' => $branded])
