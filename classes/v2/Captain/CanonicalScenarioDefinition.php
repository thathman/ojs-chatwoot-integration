<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/**
 * One canonical Captain Scenario, independent of any specific journal.
 *
 * `instructionTemplate` embeds `{{tool:<canonicalToolKey>}}` placeholders
 * — `CaptainScenarioProvisioner` resolves each to the real
 * `[Title](tool://slug)` markdown reference Chatwoot's own
 * `Captain::Scenario#resolve_tool_references` parses (verified against
 * `chatwoot/chatwoot` `develop`
 * `enterprise/app/models/concerns/captain_tools_helpers.rb`'s
 * `TOOL_REFERENCE_REGEX = /\[[^\]]+\]\(tool:\/\/([^\/)]+)\)/`). This is
 * the *only* way tools attach to a scenario — `Captain::Scenario` has a
 * `before_save :resolve_tool_references` callback that recomputes its
 * `tools` column from the instruction text on every save, so a `tools`
 * array passed directly to the create/update API is not the source of
 * truth and is not used here.
 */
final class CanonicalScenarioDefinition
{
    /** @param string[] $requiredToolKeys CanonicalToolCatalog keys this scenario's instruction references. */
    public function __construct(
        private string $key,
        private string $title,
        private string $description,
        private string $instructionTemplate,
        private array $requiredToolKeys
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function instructionTemplate(): string
    {
        return $this->instructionTemplate;
    }

    /** @return string[] */
    public function requiredToolKeys(): array
    {
        return $this->requiredToolKeys;
    }

    /**
     * @param array<string,string> $toolSlugsByKey canonical tool key => resolved remote slug
     * @param array<string,string> $toolTitlesByKey canonical tool key => tool title (for the markdown link label)
     */
    public function resolveInstruction(array $toolSlugsByKey, array $toolTitlesByKey): ?string
    {
        $instruction = $this->instructionTemplate;
        foreach ($this->requiredToolKeys as $toolKey) {
            $slug = $toolSlugsByKey[$toolKey] ?? null;
            $title = $toolTitlesByKey[$toolKey] ?? null;
            if ($slug === null || $title === null) {
                return null;
            }
            $instruction = str_replace('{{tool:' . $toolKey . '}}', "[{$title}](tool://{$slug})", $instruction);
        }
        return $instruction;
    }
}
