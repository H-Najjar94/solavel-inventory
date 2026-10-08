<?php

namespace App\Services\Integration;

final class SolaStockJournalContract
{
    public const VERSION = 'solastock-journal.v2';

    public static function canonicalJson(array $payload): string
    {
        $sort = function (&$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as &$item) {
                $sort($item);
            }
            unset($item);
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
        };
        $sort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function payloadHash(array $payload): string
    {
        return hash('sha256', self::canonicalJson($payload));
    }
}
