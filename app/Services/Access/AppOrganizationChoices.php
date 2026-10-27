<?php
namespace App\Services\Access;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class AppOrganizationChoices
{
    public function forUser(int $userId, string $appKey): array
    {
        if ($userId < 1 || ! in_array($appKey, ['finance', 'inventory', 'projects', 'hr'], true)) return [];
        return Cache::remember("authorized-app-orgs-v1:$appKey:$userId", 30, function () use ($userId, $appKey) {
            try {
                $response = Http::timeout(10)->acceptJson()
                    ->withHeaders(['X-SSO-SECRET' => (string) config('sso.shared_secret')])
                    ->post(rtrim((string) config('sso.central_app_url'), '/').'/api/sso/session-access',
                        ['action' => 'list_app_organizations', 'user_id' => $userId, 'app_key' => $appKey]);
                return $response->successful() && is_array($response->json('organizations'))
                    ? $response->json('organizations') : [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }
}

