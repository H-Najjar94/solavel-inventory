<?php

namespace App\Services\Documents;

use Illuminate\Validation\ValidationException;

/**
 * A connected organization tried to post or reverse a landed cost before its
 * landed-cost clearing account was set up. Still a ValidationException (422, the
 * same localized message), so existing callers are unchanged; the controller adds
 * the setup action the user should take.
 */
final class LandedCostSetupRequired extends ValidationException
{
    public const ACTION = 'choose_clearing_account';
}
