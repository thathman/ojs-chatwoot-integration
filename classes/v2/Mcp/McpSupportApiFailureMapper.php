<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;

/**
 * Every identity-dependent MCP tool resolves identity by calling the exact
 * same `SupportApiRequestResolver` REST uses (reading the Chatwoot
 * conversation tuple from tool arguments instead of `getUserVar()`) — this
 * maps that resolver's `SupportApiFailure` shape to the matching
 * McpErrorCode, so every such tool reports failures identically rather
 * than each reimplementing its own mapping.
 */
final class McpSupportApiFailureMapper
{
    public static function toHandlerError(SupportApiFailure $failure): McpHandlerError
    {
        $code = match ($failure->code) {
            SupportApiErrorCode::AUTHENTICATION_FAILED => McpErrorCode::UNAUTHORIZED,
            SupportApiErrorCode::VALIDATION_ERROR => McpErrorCode::INVALID_PARAMS,
            SupportApiErrorCode::RATE_LIMITED => McpErrorCode::RATE_LIMITED,
            SupportApiErrorCode::CAPABILITY_DENIED => McpErrorCode::UNAUTHORIZED,
            default => McpErrorCode::INTERNAL_ERROR,
        };

        return new McpHandlerError($code, $failure->message);
    }
}
