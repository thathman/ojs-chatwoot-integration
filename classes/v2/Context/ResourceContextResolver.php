<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Context;

/**
 * Detects the OJS resource represented by the current request/template.
 *
 * Detection is deliberately separate from relationship/capability checks.
 * Request parameters and route arguments are hints only and must be resolved
 * and authorized again before any protected data is returned.
 */
final class ResourceContextResolver
{
    public function resolve(SupportContext $supportContext, $request, $templateManager = null): ?ResourceContext
    {
        $fromTemplate = $this->fromTemplate($supportContext, $templateManager);
        if ($fromTemplate === false) {
            return null;
        }
        if ($fromTemplate instanceof ResourceContext) {
            return $fromTemplate;
        }

        $fromParameter = $this->fromSubmissionParameter($request);
        if ($fromParameter) {
            return $fromParameter;
        }

        return $this->fromKnownRoute($supportContext, $request);
    }

    /**
     * @return ResourceContext|false|null false means a template resource was
     *                                    present but invalid/cross-context.
     */
    private function fromTemplate(SupportContext $supportContext, $templateManager): ResourceContext|false|null
    {
        if (!is_object($templateManager) || !method_exists($templateManager, 'getTemplateVars')) {
            return null;
        }

        foreach (['submission', 'article'] as $templateKey) {
            try {
                $candidate = $templateManager->getTemplateVars($templateKey);
            } catch (\Throwable $e) {
                $candidate = null;
            }

            if ($candidate === null) {
                continue;
            }
            if (!is_object($candidate) || !method_exists($candidate, 'getId')) {
                return false;
            }

            $id = (int) $candidate->getId();
            if ($id <= 0 || !$this->matchesContext($candidate, $supportContext->contextId())) {
                return false;
            }

            return new ResourceContext('submission', $id, 'template:' . $templateKey);
        }

        return null;
    }

    private function fromSubmissionParameter($request): ?ResourceContext
    {
        if (!is_object($request) || !method_exists($request, 'getUserVar')) {
            return null;
        }

        try {
            $value = $request->getUserVar('submissionId');
        } catch (\Throwable $e) {
            return null;
        }

        $id = $this->positiveInt($value);
        return $id ? new ResourceContext('submission', $id, 'request_parameter') : null;
    }

    /**
     * CTX-007: the reviewer-role review page (`pages/reviewer/ReviewerHandler`,
     * operations submission/step/saveStep/showDeclineReview/saveDeclineReview/
     * downloadFile — verified against a real local pkp-lib checkout) is
     * authorized via `SubmissionAccessPolicy` -> `SubmissionRequiredPolicy` ->
     * `DataObjectRequiredPolicy::getDataObjectId()`, which reads `args[0]` as
     * the submission id exactly like the workflow page does. OJS never
     * exposes a distinct reviewId/reviewRoundId in route args at this layer
     * (review-round detail is fetched client-side via grids/API calls), so
     * there is no separate "review" resource type to detect here — only
     * confirmation that a submission is in view via the reviewer route.
     */
    private const KNOWN_ROUTES = [
        'workflow' => null,
        'reviewer' => ['submission', 'step', 'savestep', 'showdeclinereview', 'savedeclinereview', 'downloadfile'],
    ];

    private function fromKnownRoute(SupportContext $supportContext, $request): ?ResourceContext
    {
        $page = strtolower($supportContext->page());
        if (!array_key_exists($page, self::KNOWN_ROUTES)) {
            return null;
        }
        $allowedOperations = self::KNOWN_ROUTES[$page];
        if ($allowedOperations !== null && !in_array(strtolower($supportContext->operation()), $allowedOperations, true)) {
            return null;
        }
        if (!is_object($request) || !method_exists($request, 'getRequestedArgs')) {
            return null;
        }

        try {
            $args = $request->getRequestedArgs();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($args) || $args === []) {
            return null;
        }

        $id = $this->positiveInt(reset($args));
        return $id ? new ResourceContext('submission', $id, 'known_route:' . $page) : null;
    }

    private function matchesContext(object $resource, int $contextId): bool
    {
        if (!method_exists($resource, 'getData')) {
            return true;
        }

        try {
            $candidate = (int) $resource->getData('contextId');
        } catch (\Throwable $e) {
            return false;
        }

        return $candidate <= 0 || $candidate === $contextId;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }
}
