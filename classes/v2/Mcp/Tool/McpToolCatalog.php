<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

/**
 * Settings Console item H (API & MCP tab, owner directive 2026-09-04):
 * "capabilities/tools/resources summary" — a single, real source of
 * truth for which MCP tools this plugin registers, so the admin UI
 * summary can never silently drift from what `ChatwootIntegrationV2Plugin::mcpRequest()`
 * actually registers. `tests/v2/mcp-tool-catalog.php` is the drift
 * guard: it fails the moment this list's count disagrees with the
 * real number of `$registry->register(` calls in that method.
 *
 * Never touches a live request/registry itself — pure metadata only,
 * read directly from each Tool class's own `NAME`/`DESCRIPTION`
 * constants (never a second, hand-copied description).
 */
final class McpToolCatalog
{
    /** @var class-string[] Every real Tool class ChatwootIntegrationV2Plugin::mcpRequest() registers. */
    private const TOOL_CLASSES = [
        JournalProfileTool::class,
        SubmissionPolicyTool::class,
        FeePolicyTool::class,
        SupportIdentityTool::class,
        IdentityRequestVerificationTool::class,
        IdentityConfirmVerificationTool::class,
        RequiredActionsTool::class,
        SubmissionSupportStatusTool::class,
        PublicationStatusTool::class,
        PaymentStatusTool::class,
        AccountDiagnosticsTool::class,
        SubmissionDiagnosticsTool::class,
        EscalateSupportTool::class,
        SubmissionListTool::class,
        CapabilitiesListTool::class,
    ];

    public static function count(): int
    {
        return count(self::TOOL_CLASSES);
    }

    /** @return array<int,array{name:string,description:string}> */
    public static function summaries(): array
    {
        return array_map(
            static fn (string $class): array => ['name' => $class::NAME, 'description' => $class::DESCRIPTION],
            self::TOOL_CLASSES
        );
    }
}
