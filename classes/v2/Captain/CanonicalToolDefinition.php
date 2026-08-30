<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/**
 * One canonical Captain Custom Tool definition, independent of any
 * specific journal — `CaptainCustomToolProvisioner` turns this into the
 * journal-specific remote definition (endpoint URL, auth token).
 *
 * Every `params` entry is deliberately `required: true`
 * (see `CanonicalToolCatalog`'s class docblock for why) even for a
 * logically-optional field — the tool description tells the LLM to pass
 * an empty string when a field does not apply, since this codebase's own
 * endpoints already treat an empty/zero value as "not supplied."
 */
final class CanonicalToolDefinition
{
    /**
     * @param array<int,array{name:string,type:string,description:string}> $params
     */
    public function __construct(
        private string $key,
        private string $title,
        private string $description,
        private string $operation,
        private array $params
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

    public function operation(): string
    {
        return $this->operation;
    }

    /** @return array<int,array{name:string,type:string,description:string,required:bool}> */
    public function paramSchema(): array
    {
        return array_map(
            static fn (array $param): array => [
                'name' => $param['name'],
                'type' => $param['type'],
                'description' => $param['description'],
                'required' => true,
            ],
            $this->params
        );
    }

    /**
     * Builds the Liquid `request_template` JSON body: string params are
     * quoted, everything else (number) is emitted bare. Every field this
     * codebase's endpoints read via `getUserVar()` must appear here with
     * the exact same key name.
     */
    public function requestTemplate(): string
    {
        $fields = [];
        foreach ($this->params as $param) {
            $fields[] = $param['type'] === 'string'
                ? sprintf('"%s": %s', $param['name'], json_encode('{{ ' . $param['name'] . ' }}'))
                : sprintf('"%s": {{ %s }}', $param['name'], $param['name']);
        }
        // json_encode() around the Liquid placeholder above would escape the
        // {{ }} braces if they contained special characters — they don't,
        // so the placeholder survives intact and is replaced before this is
        // ever parsed as JSON by Chatwoot's own Liquid renderer.
        return '{' . implode(',', $fields) . '}';
    }
}
