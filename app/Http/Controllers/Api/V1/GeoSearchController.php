<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Geo\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** GET /geo/search (adapted from shared GeoSearchController). Returns {results, unavailable} unwrapped for the picker. */
class GeoSearchController extends ApiController
{
    public function search(Request $request, GeocodingService $geo): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'lang' => ['nullable', 'in:ar,en'],
            'countries' => ['nullable', 'string', 'max:40'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'viewbox' => ['nullable', 'string', 'max:100'],
        ]);

        $who = $request->user()?->getAuthIdentifier() ?? $request->ip();
        if (! RateLimiter::attempt('geo-search:'.$who, 10, fn () => true, 60)) {
            return response()->json(['results' => [], 'unavailable' => true], 429);
        }

        $countries = array_values(array_unique(array_merge(
            array_filter(explode(',', (string) ($data['countries'] ?? ''))),
            (array) config('services.geocoding.default_countries', ['jo', 'ae']),
        )));
        $viewbox = null;
        if (! empty($data['viewbox'])) {
            $parts = array_map('floatval', explode(',', $data['viewbox']));
            if (count($parts) === 4) {
                $viewbox = $parts;
            }
        }

        return response()->json($geo->search($data['q'], $data['lang'] ?? 'en', $countries, $viewbox));
    }
}
