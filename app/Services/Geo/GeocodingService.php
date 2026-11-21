<?php

// Adapted from shared location-maps GeocodingService (shared content sha256 524ace44e2bc; shared repo had no commit yet).
// Stock change: the upstream throttle is a host-wide FILE lock (see UpstreamThrottle below), not a cache lock.
// Config (config/services.php):
//   'geocoding' => ['provider' => env('GEOCODING_PROVIDER', 'nominatim'), // nominatim|locationiq|disabled
//                   'base_url' => env('GEOCODING_BASE_URL'), 'key' => env('GEOCODING_KEY'),
//                   'user_agent' => env('GEOCODING_USER_AGENT', 'Solavel/1.0 (+https://solavel.com)')]

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeocodingService
{
    public const CACHE_TTL_DAYS = 30;

    /**
     * @param  string[]  $countries  ISO-2 codes, lowercase
     * @param  float[]|null  $viewbox  [west, north, east, south]
     * @return array{results: array<int, array<string, mixed>>, unavailable: bool}
     */
    public function search(string $q, string $lang = 'en', array $countries = [], ?array $viewbox = null): array
    {
        $cfg = (array) config('services.geocoding', []);
        $provider = $cfg['provider'] ?? 'nominatim';
        $q = trim($q);

        if ($q === '') {
            return ['results' => [], 'unavailable' => false];
        }
        if ($provider === 'disabled' || ! in_array($provider, ['nominatim', 'locationiq'], true)) {
            return ['results' => [], 'unavailable' => true];
        }

        $lang = $lang === 'ar' ? 'ar' : 'en';
        $countries = array_values(array_unique(array_filter(array_map(fn ($c) => strtolower(trim((string) $c)), $countries), fn ($c) => preg_match('/^[a-z]{2}$/', $c))));
        sort($countries);
        $vb = $viewbox ? array_map(fn ($n) => round((float) $n, 1), $viewbox) : null; // coarse so nearby views share cache

        $key = 'geo:'.$provider.':'.md5(json_encode([mb_strtolower($q), $lang, $countries, $vb]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ['results' => $cached, 'unavailable' => false];
        }

        try {
            $raw = $this->throttled(fn () => $this->fetch($cfg, $provider, $q, $lang, $countries, $viewbox));
        } catch (Throwable $e) {
            report($e);

            return ['results' => [], 'unavailable' => true];
        }

        if ($raw === null) {
            return ['results' => [], 'unavailable' => true];
        }

        $results = array_values(array_filter(array_map([$this, 'normalize'], $raw)));
        Cache::put($key, $results, now()->addDays(self::CACHE_TTL_DAYS));

        return ['results' => $results, 'unavailable' => false];
    }

    /**
     * Host-wide upstream limiter shared by every Solavel app and FPM worker: flock on
     * <lock_dir>/upstream.lock (3 s max wait), then wait until >= 1.05 s since <lock_dir>/last-request.
     * Returns null (=> unavailable) when the lock cannot be had in time.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function throttled(callable $call): ?array
    {
        $dir = (string) (config('services.geocoding.lock_dir') ?: '/var/tmp/solavel-geocode');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 01777);
        }
        $fh = @fopen($dir.'/upstream.lock', 'c');
        if (! $fh) {
            return null;
        }
        @chmod($dir.'/upstream.lock', 0666);
        try {
            $deadline = microtime(true) + 3.0;
            while (! flock($fh, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    return null;
                }
                usleep(50_000);
            }
            $stamp = $dir.'/last-request';
            $last = is_file($stamp) ? (float) @file_get_contents($stamp) : 0.0;
            $wait = 1.05 - (microtime(true) - $last);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            try {
                return $call();
            } finally {
                @file_put_contents($stamp, sprintf('%.6F', microtime(true)));
                @chmod($stamp, 0666);
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    }

    /** @return array<int, array<string, mixed>>|null null = upstream failure */
    protected function fetch(array $cfg, string $provider, string $q, string $lang, array $countries, ?array $viewbox): ?array
    {
        $params = ['q' => $q, 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 8, 'accept-language' => $lang];
        if ($countries) {
            $params['countrycodes'] = implode(',', $countries);
        }
        if ($viewbox && count($viewbox) === 4) {
            $params['viewbox'] = implode(',', $viewbox); // west,north,east,south
            $params['bounded'] = 0;
        }
        if ($provider === 'locationiq') {
            $base = $cfg['base_url'] ?? 'https://us1.locationiq.com/v1/search';
            $params['key'] = $cfg['key'] ?? '';
            if ($params['key'] === '') {
                return null;
            }
        } else {
            $base = $cfg['base_url'] ?? 'https://nominatim.openstreetmap.org/search';
        }

        $response = Http::withHeaders(['User-Agent' => $cfg['user_agent'] ?? 'Solavel/1.0 (+https://solavel.com)', 'Accept' => 'application/json'])
            ->timeout(6)->get($base, $params);

        if ($response->status() === 404 && $provider === 'locationiq') {
            return []; // LocationIQ answers 404 for "no match"
        }
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /** @return array<string, mixed>|null */
    public function normalize(array $r): ?array
    {
        if (! isset($r['lat'], $r['lon']) || ! is_numeric($r['lat']) || ! is_numeric($r['lon'])) {
            return null;
        }
        $lat = (float) $r['lat'];
        $lng = (float) $r['lon'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }
        $a = (array) ($r['address'] ?? []);
        $first = fn (array $keys) => (string) (collect($keys)->map(fn ($k) => $a[$k] ?? null)->first(fn ($v) => is_string($v) && $v !== '') ?? '');
        $label = (string) ($r['display_name'] ?? '');
        $name = (string) ($r['name'] ?? '');
        if ($name === '') {
            $name = trim(explode(',', $label)[0]);
        }

        return [
            'label' => $label,
            'name' => $name,
            'area' => $first(['suburb', 'neighbourhood', 'quarter', 'city_district']),
            'city' => $first(['city', 'town', 'village', 'county', 'state']),
            'country' => (string) ($a['country'] ?? ''),
            'country_code' => strtolower((string) ($a['country_code'] ?? '')),
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
        ];
    }
}
