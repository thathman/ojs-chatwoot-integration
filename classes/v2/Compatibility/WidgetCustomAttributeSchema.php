<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility;

/**
 * CWO-003: the real, exhaustive, checked contract for v1's widget custom
 * attribute payload (`ChatwootIntegrationPlugin::addChatwootWidget()`'s
 * `$attrs` array) — formalizes which fields are safe to send to Chatwoot's
 * client-side SDK (a third-party, browser-visible surface) without changing
 * v1's frozen runtime behavior at all.
 *
 * This is a declarative allowlist, not a runtime filter: v1's widget script
 * generation is untouched (see the standing "no behavior change to v1"
 * policy). `tests/v2/widget-custom-attribute-schema.php` extracts the real
 * `$attrs[...]` assignments from the legacy source (the same regex CWO-004's
 * test already uses) and asserts, bidirectionally, that this list and the
 * real code never drift apart — any future addition to `$attrs` that isn't
 * also classified here fails that test immediately, and any schema entry
 * that stops being sent fails it too.
 *
 * `sensitivity`:
 * - `public`      — safe for a third-party client-side SDK; already visible
 *                    on the page itself, or journal-level/non-identifying.
 * - `user_derived` — computed from the authenticated user, but not identity
 *                    itself (never an email/name/raw user id).
 *
 * No entry here is `identity` — identity (name/email/raw numeric user id)
 * travels only through `$chatwoot.setUser()`'s own dedicated parameters,
 * never a custom attribute. See FORBIDDEN_KEYS below.
 */
final class WidgetCustomAttributeSchema
{
    /** @var array<string,array{type:string,sensitivity:string}> */
    private const ATTRIBUTES = [
        'journal_id' => ['type' => 'int', 'sensitivity' => 'public'],
        'journal_name' => ['type' => 'string', 'sensitivity' => 'public'],
        'requested_page' => ['type' => 'string', 'sensitivity' => 'public'],
        'requested_op' => ['type' => 'string', 'sensitivity' => 'public'],
        'is_masked' => ['type' => 'bool', 'sensitivity' => 'public'],
        'orcid' => ['type' => 'string', 'sensitivity' => 'user_derived'],
        'affiliation' => ['type' => 'string', 'sensitivity' => 'user_derived'],
        'roles' => ['type' => 'string', 'sensitivity' => 'user_derived'],
        'active_submissions' => ['type' => 'int', 'sensitivity' => 'user_derived'],
        'context_type' => ['type' => 'string', 'sensitivity' => 'public'],
        'article_title' => ['type' => 'string', 'sensitivity' => 'public'],
        'article_doi' => ['type' => 'string', 'sensitivity' => 'public'],
        'article_id' => ['type' => 'int', 'sensitivity' => 'public'],
        'section_title' => ['type' => 'string', 'sensitivity' => 'public'],
    ];

    /**
     * Identity-shaped keys that must never appear as a custom attribute —
     * identity travels only through `setUser()`'s own email/name/identifier
     * parameters. A future accidental `$attrs['email'] = ...` would be
     * exactly the kind of leak this schema exists to catch.
     */
    public const FORBIDDEN_KEYS = ['email', 'password', 'name', 'user_id', 'userId', 'token', 'secret', 'identifier_hash'];

    /** @return string[] */
    public static function knownKeys(): array
    {
        return array_keys(self::ATTRIBUTES);
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::ATTRIBUTES);
    }

    /** @return array{type:string,sensitivity:string}|null */
    public static function classification(string $key): ?array
    {
        return self::ATTRIBUTES[$key] ?? null;
    }
}
