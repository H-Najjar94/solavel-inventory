<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side geocoding proxy for the location picker (search on submit only).
 * Providers: nominatim (default) | locationiq | disabled. Queries are cached for
 * 30 days and upstream calls are limited to 1/s host-wide via a cache lock.
 */
final class GeocodingService
{
    private const CACHE_TTL_DAYS = 30;

    public function provider(): string
    {
        $p = strtolower((string) config('services.geocoding.provider', 'nominatim'));

        return in_array($p, ['nominatim', 'locationiq', 'disabled'], true) ? $p : 'nominatim';
    }

    /**
     * @param  array<int,string>  $countries  ISO-3166 alpha-2 codes
     * @return array{results: array<int,array<string,mixed>>, unavailable?: bool}
     */
    public function search(string $query, string $lang, array $countries, ?float $lat, ?float $lng): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if ($query === '' || mb_strlen($query) > 200 || $this->provider() === 'disabled') {
            return ['results' => [], 'unavailable' => $this->provider() === 'disabled'];
        }
        $lang = $lang === 'ar' ? 'ar' : 'en';
        $countries = collect($countries)->map(fn ($c) => strtolower(trim((string) $c)))
            ->filter(fn ($c) => preg_match('/^[a-z]{2}$/', $c))->unique()->sort()->values()->all();
        $viewbox = $lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180
            ? sprintf('%.4f,%.4f,%.4f,%.4f', $lng - 0.5, $lat + 0.5, $lng + 0.5, $lat - 0.5) : null;

        $key = 'geo:v1:'.$this->provider().':'.sha1(mb_strtolower($query).'|'.$lang.'|'.implode(',', $countries).'|'.($viewbox ? round($lat, 1).','.round($lng, 1) : ''));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ['results' => $cached];
        }

        try {
            $raw = $this->upstream($query, $lang, $countries, $viewbox);
        } catch (Throwable $e) {
            report($e);

            return ['results' => [], 'unavailable' => true];
        }
        $results = array_values(array_filter(array_map(fn ($r) => $this->normalize($r), $raw)));
        Cache::put($key, $results, now()->addDays(self::CACHE_TTL_DAYS));

        return ['results' => $results];
    }

    /** @return array<int,array<string,mixed>> */
    private function upstream(string $query, string $lang, array $countries, ?string $viewbox): array
    {
        $provider = $this->provider();
        $base = rtrim((string) (config('services.geocoding.base_url') ?: ($provider === 'locationiq' ? 'https://us1.locationiq.com/v1' : 'https://nominatim.openstreetmap.org')), '/');
        $params = [
            'q' => $query, 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 8,
            'accept-language' => $lang, 'bounded' => 0,
        ];
        if ($countries) {
            $params['countrycodes'] = implode(',', $countries);
        }
        if ($viewbox) {
            $params['viewbox'] = $viewbox;
        }
        if ($provider === 'locationiq') {
            $params['key'] = (string) config('services.geocoding.key');
        }

        // Host-wide 1 request/second: serialise through a lock and space calls.
        $lock = Cache::lock('geo:upstream-lock', 10);
        if (! $lock->block(5)) {
            throw new \RuntimeException('geocoding_busy');
        }
        try {
            $last = (float) Cache::get('geo:upstream-last', 0);
            $wait = 1.0 - (microtime(true) - $last);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            try {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('services.geocoding.user_agent', 'Solavel/1.0 (+https://solavel.com)'))
                    ->connectTimeout(3)->timeout(6)->get($base.'/search', $params);
            } finally {
                Cache::put('geo:upstream-last', microtime(true), 60);
            }
        } finally {
            $lock->release();
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('geocoding_upstream_'.$response->status());
        }

        return $response->json();
    }

    /** @return array<string,mixed>|null */
    private function normalize(mixed $r): ?array
    {
        if (! is_array($r) || ! is_numeric($r['lat'] ?? null) || ! is_numeric($r['lon'] ?? null)) {
            return null;
        }
        $a = (array) ($r['address'] ?? []);
        $name = (string) ($r['name'] ?? '');
        $area = (string) ($a['suburb'] ?? $a['neighbourhood'] ?? $a['quarter'] ?? $a['city_district'] ?? $a['district'] ?? $a['county'] ?? '');
        $city = (string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['state'] ?? '');

        return [
            'label' => (string) ($r['display_name'] ?? $name),
            'name' => $name !== '' ? $name : (string) strtok((string) ($r['display_name'] ?? ''), ','),
            'area' => $area,
            'city' => $city,
            'country' => (string) ($a['country'] ?? ''),
            'country_code' => strtolower((string) ($a['country_code'] ?? '')),
            'lat' => round((float) $r['lat'], 7),
            'lng' => round((float) $r['lon'], 7),
        ];
    }
}
