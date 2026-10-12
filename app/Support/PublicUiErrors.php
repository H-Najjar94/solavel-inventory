<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PublicUiErrors
{
    public static function localize(Response $response): Response
    {
        // Browser validation output only. Stored events, integration contracts,
        // error codes and customer data are never rewritten.
        if (! request()->is('api/v1/*') || ! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }
        $messages = __('public_errors');
        if (! is_array($messages)) {
            return $response;
        }
        $data = $response->getData(true);
        if (! is_array($data)) return $response;
        $translate = function ($value) use (&$translate, $messages) {
            if (is_string($value)) {
                return $messages[$value] ?? $value;
            }
            return is_array($value) ? array_map($translate, $value) : $value;
        };
        foreach (['message', 'errors'] as $key) {
            if (array_key_exists($key, $data)) $data[$key] = $translate($data[$key]);
        }
        if (isset($data['error']['message'])) $data['error']['message'] = $translate($data['error']['message']);
        $response->setData($data);
        return $response;
    }
}
