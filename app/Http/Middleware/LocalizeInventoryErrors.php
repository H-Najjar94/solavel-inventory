<?php

namespace App\Http\Middleware;

use App\Support\PublicUiErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalizeInventoryErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        return PublicUiErrors::localize($next($request));
    }
}
