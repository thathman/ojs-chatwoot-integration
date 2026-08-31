<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Audit\DatabaseSupportApiAuditLogger;
use APP\plugins\generic\chatwootIntegration\classes\v2\Audit\SupportApiAuditLoggerInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\RateLimiter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\ServiceTokenAuthenticator;
use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;

/**
 * The shared pipeline every Support API endpoint runs before it does
 * anything endpoint-specific:
 *
 *   authenticate service -> parse conversation metadata -> resolve support
 *   session -> reload live OJS identity -> correlation ID
 *
 * Endpoints must not each reimplement this; add new shared steps here.
 */
final class SupportApiRequestResolver
{
    public function __construct(
        private RuntimeContextBridge $bridge,
        private ?RateLimiter $rateLimiter = null,
        private ?SupportApiAuditLoggerInterface $audit = null
    ) {
        $this->rateLimiter ??= new RateLimiter();
        $this->audit ??= new DatabaseSupportApiAuditLogger();
    }

    public function resolve(
        $request,
        string $correlationId,
        int $contextId,
        string $configuredServiceTokens,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $endpoint,
        string $locale = ''
    ): SupportApiRequestContext|SupportApiFailure {
        if (!$this->transportSecure()) {
            return $this->deny($correlationId, $contextId, $endpoint, 'insecure_transport', function () use ($correlationId) {
                return new SupportApiFailure(SupportApiErrorCode::AUTHENTICATION_FAILED, 'HTTPS is required.', 400, $correlationId);
            });
        }

        if (!ServiceTokenAuthenticator::verify($configuredServiceTokens, $this->authorizationHeader())) {
            return $this->deny($correlationId, $contextId, $endpoint, 'service_auth_failed', function () use ($correlationId) {
                return new SupportApiFailure(SupportApiErrorCode::AUTHENTICATION_FAILED, 'Request could not be authenticated.', 401, $correlationId);
            });
        }

        if (!$this->rateLimiter->allow($contextId . ':' . $chatwootConversationId)) {
            return $this->deny($correlationId, $contextId, $endpoint, 'rate_limited', function () use ($correlationId) {
                return new SupportApiFailure(SupportApiErrorCode::RATE_LIMITED, 'Too many requests.', 429, $correlationId);
            });
        }

        if ($chatwootAccountId === '' || $chatwootContactId === '' || $chatwootConversationId === '') {
            return $this->deny($correlationId, $contextId, $endpoint, 'malformed_request', function () use ($correlationId) {
                return new SupportApiFailure(SupportApiErrorCode::VALIDATION_ERROR, 'Required conversation fields are missing.', 400, $correlationId);
            });
        }

        $baseIdentity = $this->bridge->resolve($request, $locale);
        if (!$baseIdentity || $baseIdentity->contextId() !== $contextId) {
            return $this->deny($correlationId, $contextId, $endpoint, 'context_unresolvable', function () use ($correlationId) {
                return new SupportApiFailure(SupportApiErrorCode::INTERNAL_ERROR, 'The request could not be completed.', 500, $correlationId);
            });
        }

        $session = $this->bridge->resolveBoundSupportSession(
            $contextId,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId
        );

        if (!$session || $session->isExpired(time())) {
            $this->allowEvent($correlationId, $contextId, $endpoint, 'unverified');
            return SupportApiRequestContext::unverified($correlationId, $contextId, $baseIdentity);
        }

        $freshIdentity = $this->bridge->resolveContextForUser($request, $session->userId(), $locale);
        if (!$freshIdentity || !$freshIdentity->isAuthenticated() || $freshIdentity->contextId() !== $contextId) {
            $this->allowEvent($correlationId, $contextId, $endpoint, 'identity_reload_failed');
            return SupportApiRequestContext::unverified($correlationId, $contextId, $baseIdentity);
        }

        $this->allowEvent($correlationId, $contextId, $endpoint, 'verified', $session->assuranceLevel());

        return SupportApiRequestContext::verifiedWith(
            $correlationId,
            $contextId,
            $session->assuranceLevel(),
            $freshIdentity,
            $session
        );
    }

    private function deny(string $correlationId, int $contextId, string $endpoint, string $reason, callable $failure): SupportApiFailure
    {
        $this->audit->record([
            'correlationId' => $correlationId,
            'endpoint' => $endpoint,
            'contextId' => $contextId,
            'decision' => 'deny',
            'reason' => $reason,
        ]);

        return $failure();
    }

    private function allowEvent(string $correlationId, int $contextId, string $endpoint, string $reason, ?string $assurance = null): void
    {
        $event = [
            'correlationId' => $correlationId,
            'endpoint' => $endpoint,
            'contextId' => $contextId,
            'decision' => 'allow',
            'reason' => $reason,
        ];
        if ($assurance !== null) {
            $event['assurance'] = $assurance;
        }
        $this->audit->record($event);
    }

    private function authorizationHeader(): ?string
    {
        return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    }

    /**
     * The service token is a bearer credential; it must never travel over
     * plain HTTP. Accepts a reverse-proxy-terminated TLS connection (the
     * X-Forwarded-Proto convention used by the nginx/cloudflared fronting
     * this plugin's real deployments) as well as a direct HTTPS connection.
     *
     * ponytail: trusts X-Forwarded-Proto unconditionally rather than
     * checking a configured trusted-proxy list, because this plugin has no
     * existing trusted-proxy concept to hook into. A request that reaches
     * PHP at all already passed through whatever reverse proxy fronts this
     * OJS install; add a trusted-proxy allowlist if that stops holding.
     */
    private function transportSecure(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        return $forwardedProto === 'https';
    }
}
