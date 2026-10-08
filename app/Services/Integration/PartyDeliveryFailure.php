<?php
namespace App\Services\Integration;

/** Safe transport diagnostics; never retain raw responses, credentials or signed bodies. */
final class PartyDeliveryFailure extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly string $errorCode,
        public readonly string $entityType, public readonly int $sourceId)
    {
        parent::__construct($errorCode);
    }

    public static function code(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-z][a-z0-9_]{0,95}\z/D', $value)
            ? $value : 'party_connection_pending';
    }
}
