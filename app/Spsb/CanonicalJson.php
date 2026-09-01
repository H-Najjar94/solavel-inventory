<?php

namespace App\Spsb;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
