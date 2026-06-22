<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a SolaStock request from a secure handoff token minted by the
 * central Solavel app — the SAME token format Finance/Projects/HR consume.
 *
 * Token = base64url( iv[16] . hmac[32] . ciphertext ), AES-256-CBC,
 *   key  = sha256(workspace_handoff_secret),
 *   hmac = HMAC-SHA256(iv . ciphertext, key).
 * Payload = { user_id, client_id, organization_id, context, exp, nonce }.
 *
 * On a valid token this seeds the session exactly like the other apps
 * (client_id / selected_central_org_id / principal), switches the SHARED tenant
 * DB (tenant_{clientId}) — without ever touching Finance's tables — and logs the
 * local user in. Must run BEFORE the `auth` middleware.
 */
class AuthenticateFromInventoryHandoff
{
    public function __construct(private TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        // On EVERY request, if the session already carries a client, point the
        // tenant connection at its database BEFORE the auth guard resolves the
        // session user. The 'users' table lives in the per-client tenant DB; on
        // un-gated routes (e.g. /tenant/status) the tenant DB would otherwise have
        // no database selected and the auth user lookup 500s.
        if ($request->hasSession()) {
            $clientId = (int) ($request->session()->get('client_id') ?? 0);
            // The org id comes from the SSO selection ONLY. Never coerce the
            // clientId into the org slot — they are different id spaces and that
            // collision scoped queries to the wrong org ("wrong org name / data").
            $orgId = (int) ($request->session()->get('selected_central_org_id') ?? 0);
            if ($clientId > 0) {
                try {
                    // The tenant DB is keyed by client (tenant_{clientId}); the org
                    // is the ROW-SCOPE only. Switch the DB always; set the org
                    // context only when a real selected org is known — otherwise
                    // LiveTenantResolver resolves the user's org for this client.
                    $db = $this->tenants->resolveDatabaseName($clientId);
                    if ($orgId > 0) {
                        $this->tenants->useTenant($orgId, $db);
                    } else {
                        $this->tenants->switchToDatabase($db);
                    }
                } catch (\Throwable $e) {
                    // non-fatal: downstream resolvers report the real state
                }
            }
        }

        $token = trim((string) $request->query('handoff', ''));
        if ($token === '') {
            return $next($request);
        }
        if (Auth::check()) {
            return redirect($this->cleanUrl($request));
        }

        $payload = $this->decrypt($token);
        if (! $payload) {
            Log::warning('[InventoryHandoff] Invalid handoff token');

            return $next($request);
        }
        if (($exp = (int) ($payload['exp'] ?? 0)) > 0 && now()->timestamp > $exp) {
            Log::warning('[InventoryHandoff] Handoff token expired');

            return $next($request);
        }

        $clientId = (int) ($payload['client_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        $orgId = (int) ($payload['organization_id'] ?? 0);
        if ($clientId <= 0 || $userId <= 0) {
            return $next($request);
        }

        // Switch to the shared per-client tenant DB (SolaStock owns its own tables there).
        try {
            $this->tenants->useTenant($clientId);
        } catch (\Throwable $e) {
            Log::error('[InventoryHandoff] Could not switch tenant connection', ['client_id' => $clientId, 'error' => $e->getMessage()]);

            return $next($request); // fall through → setup/sample state, never a 500
        }

        // Seed the session the same way the other Solavel apps do.
        if ($request->hasSession()) {
            $request->session()->put('client_id', $clientId);
            // Persist the SELECTED org from the handoff payload only. Never store
            // the clientId as the org id (different id space → wrong-org bug). If
            // the payload carried no org, LiveTenantResolver resolves the user's
            // own org under this client instead.
            if ($orgId > 0) {
                $request->session()->put('selected_central_org_id', $orgId);
            } else {
                $request->session()->forget('selected_central_org_id');
            }
            $request->session()->put('auth_context', (string) ($payload['context'] ?? 'inventory'));
        }

        // Log the central user in if a local mirror row exists; otherwise the
        // session org context is still set so the live state resolves.
        try {
            $userModel = config('auth.providers.users.model');
            if (class_exists($userModel)) {
                // The user registry lives on the central connection (see
                // User::getConnectionName) and the handoff's user_id IS the
                // central user's id, so resolve + log in directly. The previous
                // lookup queried a `central_user_id` column on the tenant
                // connection — neither the column nor a tenant users mirror
                // exists, so it always threw and no user was ever logged in
                // (leaving can_provision/permissions unresolved).
                $local = $userModel::find($userId);
                if ($local) {
                    Auth::login($local, remember: true);
                    if ($request->hasSession()) {
                        $request->session()->put('principal', ['id' => $local->id, 'name' => $local->name ?? null, 'email' => $local->email ?? null]);
                        $request->session()->regenerate();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('[InventoryHandoff] No local user mirror; continuing with org context only', ['error' => $e->getMessage()]);
        }

        return redirect($this->cleanUrl($request));
    }

    private function decrypt(string $token): ?array
    {
        $secret = (string) config('tenancy.workspace_handoff_secret', config('app.key'));
        if ($secret === '') {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 48) {
            return null;
        }
        $iv = substr($raw, 0, 16);
        $hmac = substr($raw, 16, 32);
        $ciphertext = substr($raw, 48);
        $key = hash('sha256', $secret, true);
        $expected = hash_hmac('sha256', $iv.$ciphertext, $key, true);
        if (! hash_equals($expected, $hmac)) {
            return null;
        }
        $json = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function cleanUrl(Request $request): string
    {
        $url = $request->url();
        $query = $request->except(['handoff', '_sso_tried']);

        return $query ? $url.'?'.http_build_query($query) : $url;
    }
}
