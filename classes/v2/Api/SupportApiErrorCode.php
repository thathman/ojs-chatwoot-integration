<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

/** Subset of the v2 API_MCP_SPEC error taxonomy actually in use so far. */
final class SupportApiErrorCode
{
    public const AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
}
