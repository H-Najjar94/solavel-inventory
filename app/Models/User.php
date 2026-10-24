<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function centralAvatarUrl(): ?string
    {
        $base = rtrim((string) config('sso.central_app_url', 'https://solavel.com'), '/');
        $version = max(1, (int) ($this->avatar_version ?? 1));
        if (!empty($this->avatar_path)) return $base.'/storage/'.ltrim((string) $this->avatar_path, '/').'?v='.$version;
        if (($this->avatar_kind ?? null) === 'generated' && preg_match('/\A[a-f0-9]{32}\z/', (string) $this->avatar_seed)
            && preg_match('/\A[a-z]+\z/', (string) $this->avatar_style) && preg_match('/\A[a-z]+\z/', (string) $this->avatar_color)) {
            return $base."/avatars/generated/{$this->avatar_style}/{$this->avatar_color}/{$this->avatar_seed}.svg?v={$version}";
        }
        return null;
    }

    /**
     * The user registry is a LANDLORD table (database/migrations/landlord),
     * so it lives on the central connection — NOT a per-tenant DB. The app's
     * default connection is `tenant` (DB_CONNECTION=tenant), whose database is
     * only selected at runtime by ResolveInventoryTenant. Auth, however, runs
     * BEFORE tenant resolution (the SSO/handoff middleware resolves Auth::user()
     * first), so without this override `select * from users where id = ?` would
     * hit the `tenant` connection with no database selected and 500 with
     * "SQLSTATE[3D000] No database selected". Pinning to the central connection
     * (same pattern as App\Models\Landlord\* models) makes auth work regardless
     * of tenant state. See [[inventory-onboarding-wizard]].
     */
    public function getConnectionName()
    {
        return config('tenancy.central_connection', 'mysql');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
