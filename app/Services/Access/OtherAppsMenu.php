<?php

namespace App\Services\Access;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Solavel portal card and the other Solavel apps the signed-in user may open
 * for the current organization, for the top-bar account menu (same contract as
 * SolaHR's OtherAppsMenu). Access is Central's decision (the same session-access
 * check SolaStock uses for itself), cached briefly per user/org/app so the menu
 * never adds steady load on Central. Display only: every app re-checks on arrival.
 */
class OtherAppsMenu
{
    /** slug => [name, logo (under public/), how Central launches it for an organization] */
    private const APPS = [
        'finance' => ['name' => 'SolaCount', 'logo' => 'imgs/apps/solacount.svg', 'path' => '/sso/finance/redirect'],
        'projects' => ['name' => 'SolaProjects', 'logo' => 'imgs/apps/solaprojects.svg', 'path' => '/sso/projects/redirect'],
        'hr' => ['name' => 'SolaHR', 'logo' => 'imgs/apps/solahr.svg', 'path' => '/sso/hr/redirect'],
    ];

    public function __construct(private readonly CentralAppAccess $access)
    {
    }

    public static function centralBase(): string
    {
        return rtrim((string) (config('sso.central_app_url') ?: config('tenancy.parent_base_url', '')), '/');
    }

    /** Central portal URL (optionally a path inside it) in the current language. */
    public static function portalUrl(string $path = '/portal'): ?string
    {
        $central = self::centralBase();
        if ($central === '') {
            return null;
        }

        return $central.'/'.ltrim($path, '/').'?'.http_build_query(['lang' => app()->getLocale() === 'ar' ? 'ar' : 'en']);
    }

    /**
     * The portal's apps & plans page for one organization. Central keys portal
     * organizations by slug (its /portal/orgs/by-id/{id}/… shortcut currently
     * fails with an ambiguous-column SQL error), so resolve the slug from the
     * shared central registry; without one, the organizations list.
     */
    public static function manageAppsUrl(?int $centralOrganizationId): ?string
    {
        $slug = '';
        if ($centralOrganizationId) {
            try {
                $slug = trim((string) (DB::connection('mysql')->table('organizations')
                    ->where('id', $centralOrganizationId)->value('slug') ?? ''));
            } catch (\Throwable) {
                $slug = '';
            }
        }

        return self::portalUrl($slug !== '' ? '/portal/orgs/'.rawurlencode($slug).'/projects' : '/portal/orgs');
    }

    /** @return array{portal: ?array{url: string, logo: string}, apps: list<array{key: string, name: string, url: string, logo: string}>} */
    public function forUser(int $centralUserId, ?int $centralOrganizationId): array
    {
        $central = self::centralBase();
        if ($central === '') {
            return ['portal' => null, 'apps' => []];
        }
        $portal = ['url' => (string) self::portalUrl(), 'logo' => asset('imgs/apps/solavel.svg')];
        if ($centralUserId < 1 || ! $centralOrganizationId) {
            return ['portal' => $portal, 'apps' => []];
        }

        $apps = [];
        foreach (self::APPS as $slug => $app) {
            $key = "inventory.other-apps.{$centralUserId}.{$centralOrganizationId}.{$slug}";
            $allowed = Cache::get($key);
            if (! is_bool($allowed)) {
                $decision = $this->access->decision($centralUserId, $centralOrganizationId, $slug);
                $allowed = ($decision['allowed'] ?? false) === true;
                // An indeterminate answer (Central busy) is not remembered as "no".
                if ($allowed || ($decision['reason'] ?? '') !== 'temporarily_unavailable') {
                    Cache::put($key, $allowed, now()->addMinutes(5));
                }
            }
            if (! $allowed) {
                continue;
            }
            $apps[] = [
                'key' => $slug,
                'name' => $app['name'],
                'url' => $central.$app['path'].'?'.http_build_query(['organization_id' => $centralOrganizationId]),
                'logo' => asset($app['logo']),
            ];
        }

        return ['portal' => $portal, 'apps' => $apps];
    }
}
