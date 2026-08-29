<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

/** A structural/auth failure the caller must reject with a real error response (not the generic "unverified" success). */
final class SupportApiFailure
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $httpStatus,
        public readonly string $correlationId
    ) {
    }
}
