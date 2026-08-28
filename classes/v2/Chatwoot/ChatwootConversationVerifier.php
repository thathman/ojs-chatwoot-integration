<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootConversationClientInterface;

/**
 * Converts Chatwoot API output into trusted conversation-binding evidence.
 *
 * Browser/Captain supplied account/contact IDs are never accepted here. The
 * only browser hint is the display conversation ID, which is re-fetched from
 * Chatwoot using the server-side API token.
 */
final class ChatwootConversationVerifier
{
    public function __construct(
        private ChatwootConversationClientInterface $client,
        private int $expectedInboxId = 0
    ) {
    }

    public function verify(int $conversationDisplayId, string $expectedContactIdentifier): ?VerifiedConversationBinding
    {
        if ($conversationDisplayId <= 0 || trim($expectedContactIdentifier) === '') {
            return null;
        }

        $conversation = $this->client->getConversation($conversationDisplayId);
        if (!$conversation) {
            return null;
        }

        $accountId = (int) ($conversation['account_id'] ?? 0);
        $inboxId = (int) ($conversation['inbox_id'] ?? 0);
        $returnedConversationId = (int) ($conversation['id'] ?? 0);
        $meta = $conversation['meta'] ?? null;
        $sender = is_array($meta) ? ($meta['sender'] ?? null) : null;

        if (
            $accountId <= 0
            || $accountId !== $this->client->getAccountId()
            || $returnedConversationId !== $conversationDisplayId
            || $inboxId <= 0
            || ($this->expectedInboxId > 0 && $inboxId !== $this->expectedInboxId)
            || !is_array($meta)
            || ($meta['hmac_verified'] ?? false) !== true
            || !is_array($sender)
        ) {
            return null;
        }

        $contactId = (int) ($sender['id'] ?? 0);
        $contactIdentifier = trim((string) ($sender['identifier'] ?? ''));
        if (
            $contactId <= 0
            || $contactIdentifier === ''
            || !hash_equals(trim($expectedContactIdentifier), $contactIdentifier)
        ) {
            return null;
        }

        return new VerifiedConversationBinding(
            $accountId,
            $contactId,
            $returnedConversationId,
            $inboxId,
            $contactIdentifier
        );
    }
}
