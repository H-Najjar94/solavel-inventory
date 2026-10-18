{{--
    Branded 403 with denial variants: role | plan | noapp | org | setup | membership | email | sso.
    Resolves the variant from (first match wins): $variant, $denial_code, $reason, $reason_code,
    session('deny_response.reason'), the X-Deny-Reason header, then $feature (=> plan), else role.
    Optional inputs: $appName, $permission / $permission_key / $required_permission (+ $permission_label),
    $feature (+ $feature_label), $current_organization / $organization, $message / $denial_reason,
    $planUrl / $plans_url / $renewUrl, $setupUrl, $exception (Spatie UnauthorizedException => permission).
--}}
@php
    $t = static fn (string $key, array $replace = []): string => (string) trans('solavel_errors.'.$key, $replace);
    $has = static fn (string $name): bool => \Illuminate\Support\Facades\Route::has($name);
    $headline = static fn (string $v): string => \Illuminate\Support\Str::headline(str_replace(['.', ':'], ' ', $v));
    $appLabel = (string) ($appName ?? $t('app_name'));
    $deny = (array) session('deny_response', []);

    $mapReason = static function (?string $raw): ?string {
        $r = strtolower(trim((string) $raw));
        if ($r === '') return null;
        if (in_array($r, ['role', 'plan', 'noapp', 'org', 'setup', 'membership', 'email', 'sso'], true)) return $r;
        if ($r === 'plan_feature_required' || str_starts_with($r, 'limit_reached') || str_starts_with($r, 'feature_') || str_contains($r, 'not_in_plan')) return 'plan';
        if (in_array($r, ['no_subscription', 'app_not_purchased', 'subscription_missing_project', 'expired_entitlement', 'billing_split_brain', 'project_disabled_on_org', 'project_disabled_for_org', 'project_not_enabled'], true)) return 'noapp';
        if (in_array($r, ['org_forbidden', 'organization_forbidden', 'not_org_member', 'client_inactive', 'org_not_selected'], true)) return 'org';
        if (in_array($r, ['setup_incomplete', 'provisioning_failed', 'tenant_schema_not_ready', 'membership_or_setup_unavailable', 'hr_setup_incomplete'], true)) return 'setup';
        if ($r === 'membership_inactive' || $r === 'member_inactive') return 'membership';
        if ($r === 'email_verification_required' || $r === 'email_not_verified') return 'email';
        if (str_starts_with($r, 'sso_') || in_array($r, ['invalid_token', 'token_expired', 'login_link_expired'], true)) return 'sso';
        if (in_array($r, ['permission_denied', 'action_forbidden', 'access_denied', 'access_required', 'project_not_assigned_to_user', 'user_not_assigned_to_project'], true)) return 'role';
        if (str_starts_with($r, 'project_')) return 'noapp';

        return null;
    };
    $resolved = null;
    foreach ([$variant ?? null, $denial_code ?? null, $reason ?? null, $reason_code ?? null, data_get($deny, 'reason'), request()->header('X-Deny-Reason')] as $candidate) {
        if (is_string($candidate) && ($resolved = $mapReason($candidate)) !== null) break;
    }
    $resolved ??= ! empty($feature) ? 'plan' : 'role';

    // Permission (Spatie role denials) and feature labels.
    $permKey = $permission ?? $permission_key ?? $required_permission ?? null;
    if (! $permKey && isset($exception) && $exception instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
        $permKey = $exception->getRequiredPermissions()[0] ?? null;
    }
    $permLabel = $permission_label ?? null;
    if (! $permLabel && is_string($permKey) && $permKey !== '') {
        foreach (['hr/permissions', 'finance/permissions', 'permissions'] as $file) {
            $map = trans($file);
            if (is_array($map) && isset($map[$permKey])) {
                $entry = $map[$permKey];
                $permLabel = is_array($entry) ? ($entry['label'] ?? null) : (is_string($entry) ? $entry : null);
                if ($permLabel) break;
            }
        }
        $permLabel = $permLabel ?: $headline(preg_replace('/^(hr|finance|projects|inventory|stock|central)\./', '', $permKey));
    }
    $featureKey = is_string($feature ?? null) ? $feature : null;
    $featureLabel = $feature_label ?? ($featureKey ? $headline(preg_replace('/^(hr|finance|projects|inventory|stock)\./', '', $featureKey)) : null);
    $org = $current_organization ?? $organization ?? null;
    $orgName = is_object($org) ? (string) ($org->name ?? '') : '';

    // URLs.
    $centralBase = rtrim((string) (config('sso.central_app_url') ?: 'https://solavel.com'), '/');
    try { $portalUrl = $portalUrl ?? ($has('portal.home') ? route('portal.home') : $centralBase.'/portal'); } catch (\Throwable) { $portalUrl = $centralBase.'/portal'; }
    try { $plansUrl = $planUrl ?? $plans_url ?? $renewUrl ?? ($has('client.plans.index') ? route('client.plans.index') : $centralBase.'/portal/plan'); } catch (\Throwable) { $plansUrl = $centralBase.'/portal/plan'; }
    try { $loginUrl = $has('login') ? route('login') : $portalUrl; } catch (\Throwable) { $loginUrl = $portalUrl; }
    $homeUrl = url('/');
    $setupTarget = $setupUrl ?? $homeUrl;
    try {
        $verifyUrl = $has('verification.notice')
            ? route('verification.notice', array_filter(['app' => $appKey ?? null, 'organization_id' => is_object($org) ? ($org->id ?? null) : request()->query('organization_id')]))
            : $portalUrl;
    } catch (\Throwable) { $verifyUrl = $portalUrl; }

    $portalAction = ['style' => 'blue', 'icon' => 'portal', 'label' => $t('action.portal'), 'subtitle' => $t('action.portal_sub'), 'url' => $portalUrl];
    $switchAction = ['style' => 'blue', 'icon' => 'swap', 'label' => $t('action.switch_org'), 'subtitle' => $t('action.switch_org_sub'), 'url' => $portalUrl];
    $dashboardAction = ['style' => 'primary', 'icon' => 'home', 'label' => $t('action.dashboard'), 'subtitle' => $t('action.dashboard_sub'), 'url' => $homeUrl];

    $v = [
        'role' => ['key', '403', 'role', [$dashboardAction, $switchAction]],
        'plan' => ['sparkle', 'plan', 'plan', [['style' => 'red', 'icon' => 'card', 'label' => $t('action.upgrade'), 'subtitle' => $t('action.upgrade_sub'), 'url' => $plansUrl], $dashboardAction]],
        'noapp' => ['card', 'app', 'noapp', [['style' => 'red', 'icon' => 'card', 'label' => $t('action.subscribe', ['app' => $appLabel]), 'subtitle' => $t('action.subscribe_sub'), 'url' => $plansUrl], $portalAction]],
        'org' => ['building', 'org', 'org', [array_replace($switchAction, ['style' => 'primary']), $portalAction]],
        'setup' => ['tool', 'setup', 'setup', [['style' => 'primary', 'icon' => 'tool', 'label' => $t('action.finish_setup'), 'subtitle' => $t('action.finish_setup_sub'), 'url' => $setupTarget], $portalAction]],
        'membership' => ['pause', 'membership', 'membership', [$portalAction]],
        'email' => ['mail', 'email', 'email', [['style' => 'primary', 'icon' => 'mail', 'label' => $t('action.verify_email'), 'subtitle' => $t('action.verify_email_sub'), 'url' => $verifyUrl], $portalAction]],
        'sso' => ['login', 'sso', 'sso', [['style' => 'primary', 'icon' => 'login', 'label' => $t('action.sign_in'), 'subtitle' => $t('action.sign_in_sub'), 'url' => $loginUrl], $portalAction]],
    ][$resolved];
    [$icon, $chipKey, $copyKey, $actions] = $v;

    $line = match ($resolved) {
        'role' => $permLabel ? $t('role.line', ['permission' => $permLabel]) : $t('role.line_generic'),
        'plan' => $featureLabel ? $t('plan.line', ['feature' => $featureLabel]) : $t('plan.line_generic'),
        default => $t($copyKey.'.line', ['app' => $appLabel]),
    };

    $rows = array_values(array_filter([
        $permLabel && $resolved === 'role' ? [$t('info.permission'), $permLabel] : null,
        $featureLabel && $resolved === 'plan' ? [$t('info.feature'), $featureLabel] : null,
        $orgName !== '' ? [$t('info.organization'), $orgName] : null,
        in_array($resolved, ['noapp', 'setup'], true) ? [$t('info.app'), $appLabel] : null,
    ]));
    // A caller-supplied human message is kept as context, but never Spatie's technical text.
    $detail = $message ?? $denial_reason ?? data_get($deny, 'message') ?? null;
    if (! is_string($detail) || $detail === '' || str_contains($detail, 'does not have the right') || $detail === 'This action is unauthorized.') {
        $detail = null;
    }

    $branded = [
        'status' => (int) ($status_code ?? 403),
        'icon' => $icon,
        'chip' => $t('chip.'.$chipKey),
        'title' => $t($copyKey.'.title', ['app' => $appLabel]),
        'line' => $line,
        'info' => ($rows !== [] || $detail) ? ['label' => $t('info.what_happened'), 'text' => $detail, 'rows' => $rows] : null,
        'actions' => $actions,
        'report' => false,
    ];
@endphp
@include('errors._branded', ['b' => $branded])
