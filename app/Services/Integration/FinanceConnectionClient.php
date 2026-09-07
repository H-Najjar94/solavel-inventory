<?php

namespace App\Services\Integration;

use App\Services\InventoryWorkspace\WorkspaceSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FinanceConnectionClient
{
    private const PATH = '/api/internal/stock-connection';

    public function command(array $payload): array
    {
        $base = preg_replace('#/api/v1/?$#', '', rtrim((string) config('services.solabooks.api_base_url'), '/'));
        $secret = (string) config('finance_workspace.secret', '');
        if (! filter_var($base, FILTER_VALIDATE_URL) || strlen($secret) < 32
            || (parse_url($base, PHP_URL_SCHEME) !== 'https' && ! app()->environment('testing'))) {
            throw new RuntimeException('finance_connection_service_unconfigured');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(24));
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(20)->withoutRedirecting()->withHeaders([
                'X-Workspace-Timestamp' => $timestamp, 'X-Workspace-Nonce' => $nonce,
                'X-Workspace-Signature' => WorkspaceSignature::signForPath(self::PATH, $body, $timestamp, $nonce, $secret),
            ])->withBody($body, 'application/json')->post($base.self::PATH);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('finance_connection_transport_unknown_retry_same_key', 503, $exception);
        }
        $result = $response->json();
        if (! $response->successful() || ! is_array($result) || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException((string) (data_get($result, 'message') ?: 'finance_connection_command_failed'), $response->status());
        }
        return $result['data'];
    }
}
