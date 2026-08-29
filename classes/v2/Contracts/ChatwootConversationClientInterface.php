<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

/**
 * Minimal server-side Chatwoot client contract required to prove a binding.
 */
interface ChatwootConversationClientInterface
{
    public function getAccountId(): int;

    /** @return array<string,mixed>|null */
    public function getConversation(int $conversationDisplayId): ?array;
}
