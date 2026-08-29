<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

/**
 * The one place that knows how a Support Gateway REST response is
 * transported: content type, HTTP status, no-store caching, JSON shape.
 *
 * Every server-to-server Support API endpoint (status/identity/actions and
 * anything added later) emits through here instead of building its own
 * envelope, so the wire contract can evolve in one place. This is
 * deliberately separate from PKP's JSONMessage, which /bind keeps using —
 * that endpoint is part of the OJS/browser handshake with its own existing
 * JS contract, not a Captain-facing service API.
 */
final class SupportApiResponse
{
    private const API_VERSION = 'v1';

    /** @param array<string,mixed> $data */
    public static function success(array $data, string $correlationId, int $httpStatus = 200): never
    {
        self::emit($httpStatus, [
            'ok' => true,
            'data' => $data,
            'meta' => self::meta($correlationId),
        ]);
    }

    public static function error(string $code, string $message, string $correlationId, int $httpStatus = 400): never
    {
        self::emit($httpStatus, [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => self::meta($correlationId),
        ]);
    }

    /** @return array<string,string> */
    private static function meta(string $correlationId): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'correlationId' => $correlationId,
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function emit(int $httpStatus, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{"ok":false,"error":{"code":"INTERNAL_ERROR","message":"Response could not be encoded."}}';
        exit;
    }
}
