<?php

namespace App\Services\Access;

/**
 * Full SolaStock authority, derived only from a Central app-access decision.
 *
 * Two members hold it: the organization owner, and a member Central gave the
 * SolaStock Administrator app role (`inventory_administrator`). Inside
 * SolaStock both are treated alike: every native permission (Central explicit
 * denials still bind), every warehouse, and management of other members. The
 * administrator role grants nothing outside SolaStock; decisions that are
 * really about the organization (e.g. the SolaCount connection policy) keep
 * reading the literal organization-owner membership instead.
 */
final class AppAuthority
{
    public const ADMINISTRATOR_ROLE = 'inventory_administrator';

    public static function full(array $decision): bool
    {
        if (! ($decision['allowed'] ?? false)) {
            return false;
        }

        return (bool) ($decision['owner'] ?? false)
            || in_array(self::ADMINISTRATOR_ROLE, (array) ($decision['roles'] ?? []), true);
    }
}
