<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics;

use GuzzleHttp\Exception\RequestException;

/**
 * HAR-011: raw `$e->getMessage()` from an external HTTP/SMTP/provider
 * exception can embed the full request URI (including query-string
 * tokens), response bodies, or other operator-visible-but-sensitive
 * data — Guzzle in particular includes the full request/response in
 * `RequestException::getMessage()`. Never log or return that text
 * directly; describe() gives a safe, still-useful summary instead
 * (exception class + HTTP status when available), never the raw
 * message.
 */
final class SafeExceptionMessage
{
    public static function describe(\Throwable $e): string
    {
        $label = (new \ReflectionClass($e))->getShortName();

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            if ($response !== null) {
                return "{$label} (HTTP {$response->getStatusCode()})";
            }
        }

        return $label;
    }
}
