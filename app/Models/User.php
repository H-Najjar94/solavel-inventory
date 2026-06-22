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
