<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ApiResponses;

/**
 * Base for all SolaStock JSON API controllers. Controllers stay THIN: they
 * validate (FormRequests), authorize (policies/permissions), call application
 * services, and return via the ApiResponses envelope. No controller writes
 * stock tables directly — all stock mutations go through the Stock services.
 */
abstract class ApiController extends Controller
{
    use ApiResponses;
}
