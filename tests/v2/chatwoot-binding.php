<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot\ChatwootConversationVerifier;
use APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot\LegacyWidgetIdentifierResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootConversationClientInterface;

function bindingCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeChatwootConversationClient implements ChatwootConversationClientInterface
{
    public function __construct(private int $accountId, private ?array $conversation) {}
    public function getAccountId(): int { return $this->accountId; }
    public function getConversation(int $conversationDisplayId): ?array { return $this->conversation; }
}

function conversationFixture(array $overrides = []): array
{
    $base = [
        'id' => 500,
        'account_id' => 1,
        'inbox_id' => 9,
        'meta' => [
            'hmac_verified' => true,
            'sender' => [
                'id' => 100,
                'identifier' => '42',
                'email' => 'author@example.test',
            ],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

$verified = (new ChatwootConversationVerifier(
    new FakeChatwootConversationClient(1, conversationFixture()),
    9
))->verify(500, '42');
bindingCheck($verified !== null, 'valid Chatwoot conversation should verify');
bindingCheck($verified?->accountId() === 1, 'account ID must come from Chatwoot evidence');
bindingCheck($verified?->contactId() === 100, 'contact ID must come from Chatwoot evidence');
bindingCheck($verified?->conversationId() === 500, 'conversation ID must be server-confirmed');
bindingCheck($verified?->inboxId() === 9, 'inbox ID must be server-confirmed');

$wrongHmac = (new ChatwootConversationVerifier(
    new FakeChatwootConversationClient(1, conversationFixture(['meta' => ['hmac_verified' => false]])),
    9
))->verify(500, '42');
bindingCheck($wrongHmac === null, 'non-HMAC-verified contact inbox must fail closed');

$wrongAccount = (new ChatwootConversationVerifier(
    new FakeChatwootConversationClient(2, conversationFixture()),
    9
))->verify(500, '42');
bindingCheck($wrongAccount === null, 'conversation account must match server API account');

$wrongInbox = (new ChatwootConversationVerifier(
    new FakeChatwootConversationClient(1, conversationFixture()),
    10
))->verify(500, '42');
bindingCheck($wrongInbox === null, 'conversation must belong to configured OJS Chatwoot inbox');

$wrongConversation = (new ChatwootConversationVerifier(
    new FakeChatwootConversationClient(1, conversationFixture(['id' => 501])),
    9
))->verify(500, '42');
bindingCheck($wrongConversation === null, 'API response conversation must match browser hint');

$wrongIdentifier = (new ChatwootConversationVerifier(
    new FakeChatwootConversationClient(1, conversationFixture()),
    9
))->verify(500, '43');
bindingCheck($wrongIdentifier === null, 'contact identifier must match current OJS widget identity');

$missingSender = (new ChatwootConversationVerifier(
    new FakeChatwootConversationClient(1, conversationFixture(['meta' => ['sender' => null]])),
    9
))->verify(500, '42');
bindingCheck($missingSender === null, 'missing sender evidence must fail closed');

$resolver = new LegacyWidgetIdentifierResolver();
bindingCheck($resolver->resolve(42, 7, false) === '42', 'normal widget identifier should match OJS user ID');
bindingCheck(
    $resolver->resolve(42, 7, true) === 'reviewer_' . hash('sha256', '427'),
    'temporary reviewer identifier resolver must exactly mirror v1 privacy masking'
);

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
bindingCheck(str_contains($pluginSource, "Hook::add('LoadHandler'"), 'plugin must register same-origin support gateway handler');
bindingCheck(str_contains($pluginSource, 'chatwoot:on-message'), 'browser handshake must wait for actual Chatwoot conversation event');
bindingCheck(str_contains($pluginSource, 'X-CSRF-TOKEN'), 'browser handshake must use OJS CSRF header');
bindingCheck(str_contains($pluginSource, 'bindingToken'), 'browser handshake must submit one-time ticket');
bindingCheck(str_contains($pluginSource, 'conversationId'), 'browser handshake must submit conversation display ID');
bindingCheck(!str_contains($pluginSource, 'ojs_binding_token'), 'binding ticket must not be persisted as a Chatwoot custom attribute');
bindingCheck(str_contains($pluginSource, 'ChatwootConversationVerifier'), 'endpoint must verify Chatwoot server evidence before consuming ticket');

$handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
bindingCheck(str_contains($handlerSource, 'HTTP_X_CSRF_TOKEN'), 'handler must fail closed on missing CSRF even in addition to PKP middleware');
bindingCheck(str_contains($handlerSource, 'hash_equals'), 'handler CSRF comparison must be timing-safe');

$apiSource = (string) file_get_contents($root . '/ChatwootApiService.php');
bindingCheck(str_contains($apiSource, 'getConversation'), 'server Chatwoot API adapter must expose conversation lookup');
bindingCheck(str_contains($apiSource, 'accounts/{$this->accountId}/conversations/{$conversationDisplayId}'), 'conversation lookup must be account-scoped');

$repositorySource = (string) file_get_contents($root . '/classes/v2/Session/DatabaseSupportSessionRepository.php');
bindingCheck(str_contains($repositorySource, "where('user_id', \$userId)"), 'atomic ticket claim must include current OJS user');

fwrite(STDOUT, "Chatwoot binding tests passed\n");
