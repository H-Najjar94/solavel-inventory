<?php
namespace App\Services\SystemEvents;

use Illuminate\Http\Request;

/** Request-local attribution. bind() must only run after raw-body HMAC verification. */
final class VerifiedProvisioningContext
{
    private const ATTRIBUTE = '_verified_central_provisioning_context';

    public static function bind(Request $request): void
    {
        $body = json_decode($request->getContent(), true);
        if (! is_array($body)) return;
        $positive = static fn ($value) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $text = static fn ($value, $max) => is_string($value) && strlen($value) <= $max ? $value : null;
        $actor = is_array($body['_initiator'] ?? null) ? $body['_initiator'] : [];
        $id = $positive($actor['user_id'] ?? null);
        $org = $positive($body['organization_id'] ?? null);
        $context = [
            'client_id' => $positive($body['client_id'] ?? null),
            'organization_id' => $org,
            'user' => ['id' => $id, 'name' => $id ? $text($actor['user_name'] ?? null, 255) : null,
                'email' => $id ? $text($actor['user_email'] ?? null, 255) : null, 'role' => null],
            'metadata' => ['central_org_id' => $org, 'verified_provisioning_scope' => true],
        ];
        if ($id && ($requestId = $text($actor['request_id'] ?? null, 128))) $context['request_id'] = $requestId;
        if ($correlation = $text($body['correlation_id'] ?? null, 128)) $context['correlation_id'] = $correlation;
        $request->attributes->set(self::ATTRIBUTE, $context);
    }

    public static function get(Request $request): array
    {
        return (array) $request->attributes->get(self::ATTRIBUTE, []);
    }
}
