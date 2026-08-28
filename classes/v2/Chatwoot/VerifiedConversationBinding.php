<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot;

/**
 * Server-verified Chatwoot conversation identity evidence.
 */
final class VerifiedConversationBinding
{
    public function __construct(
        private int $accountId,
        private int $contactId,
        private int $conversationId,
        private int $inboxId,
        private string $contactIdentifier
    ) {
    }

    public function accountId(): int { return $this->accountId; }
    public function contactId(): int { return $this->contactId; }
    public function conversationId(): int { return $this->conversationId; }
    public function inboxId(): int { return $this->inboxId; }
    public function contactIdentifier(): string { return $this->contactIdentifier; }
}
