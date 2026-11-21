<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Geo\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** GET /geo/search?q=&lang=ar|en&countries=jo,ae&lat=&lng= (submit-only search proxy). */
final class GeoSearchController extends ApiController
{
    public function search(Request $request, GeocodingService $geo): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'lang' => ['nullable', 'in:ar,en'],
            'countries' => ['nullable', 'string', 'max:40'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $who = $request->user()?->getAuthIdentifier() ?? $request->ip();
        if (! RateLimiter::attempt('geo-search:'.$who, 10, fn () => true, 60)) {
            return $this->error('geo_rate_limited', 'Too many searches, try again in a minute.', 429);
        }

        $countries = array_values(array_unique(array_merge(
            array_filter(explode(',', (string) ($data['countries'] ?? ''))),
            (array) config('services.geocoding.default_countries', ['jo', 'ae']),
        )));

        return $this->success($geo->search(
            $data['q'], $data['lang'] ?? 'en', $countries,
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        ));
    }
}
