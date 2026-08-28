<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Context;

/**
 * Converts trusted server-side support context into display-only Chatwoot
 * custom attributes. Deliberately excludes user identifiers, email addresses,
 * submission IDs, relationship claims and capabilities.
 */
final class ChatwootContextProjector
{
    private SupportIntentResolver $intentResolver;

    public function __construct(?SupportIntentResolver $intentResolver = null)
    {
        $this->intentResolver = $intentResolver ?? new SupportIntentResolver();
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function project(SupportContext $context): array
    {
        return [
            'ojs_context_schema' => 'v2',
            'ojs_context_contract' => 'display_only',
            'ojs_context_id' => $context->contextId(),
            'ojs_context_path' => $context->contextPath(),
            'ojs_authenticated' => $context->isAuthenticated(),
            'ojs_role_ids' => implode(',', $context->roleIds()),
            'ojs_has_multiple_roles' => count($context->roleIds()) > 1,
            'ojs_requested_page' => $context->page(),
            'ojs_requested_op' => $context->operation(),
            'ojs_locale' => $context->locale(),
            'ojs_support_intent' => $this->intentResolver->resolve($context),
        ];
    }
}
