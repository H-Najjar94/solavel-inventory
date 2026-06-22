<?php

/**
 * NOTE ON DIVERGENCE FROM solavel-finance:
 * Finance ships the stock Laravel server.php. SolaStock deliberately keeps this
 * custom dev router because its Blade route `/solastock` (the dashboard preview)
 * collides with the real asset directory `public/solastock/`; the stock router
 * would serve the directory and 404 before Laravel runs. This affects ONLY the
 * `php artisan serve` / `php -S` dev server — production (Apache) is unaffected.
 * Everything else in the tenancy/env stack matches Finance.
 *
 * Custom router for `php artisan serve` / `php -S` (PHP built-in web server).
 *
 * Why this exists:
 * Laravel's default dev router returns `false` for any URI that maps to an
 * existing path under the public dir. That breaks a Blade route whose URI
 * collides with a real asset *directory* — here `/solastock` (route) vs
 * `public/solastock/` (assets): the built-in server tries to serve the
 * directory and returns its own 404 before Laravel ever sees the request.
 *
 * Fix: this router serves real *files* itself (with correct MIME types) and
 * sends every other URI — including ones that match a directory name — to
 * Laravel's front controller. It is self-contained so it works no matter what
 * doc root the server was started with.
 *
 * Run with:   php -S 127.0.0.1:8000 -t public server.php
 *       or:   php artisan serve   (Laravel auto-uses public/ as doc root)
 */

$publicPath = __DIR__.'/public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

$file = realpath($publicPath.$uri);

// Serve a real static file directly (must stay inside public/, never a dir).
if ($uri !== '/' && $file !== false && is_file($file) && str_starts_with($file, realpath($publicPath))) {
    $mimes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'jsx'  => 'text/jsx',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'  => 'font/ttf',
        'map'  => 'application/json',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (isset($mimes[$ext])) {
        header('Content-Type: '.$mimes[$ext]);
    }
    readfile($file);
    return true;
}

// Everything else -> Laravel.
require_once $publicPath.'/index.php';
