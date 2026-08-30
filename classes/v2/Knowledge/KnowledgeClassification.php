<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Journal Knowledge Compiler fact classification (docs/v2/KNOWLEDGE_DIAGNOSTICS.md
 * §2, tightened per the compiler-freeze directive: only three values, and
 * `PUBLIC` is the one classification KnowledgeCompiler will ever surface on
 * a generated page). Deliberately strict and small — a fact with a
 * classification outside this set is a bug in its provider, not a value to
 * interpret charitably.
 */
final class KnowledgeClassification
{
    public const PUBLIC = 'public';
    public const PRIVATE = 'private';
    public const UNSUPPORTED = 'unsupported';

    public const ALL = [self::PUBLIC, self::PRIVATE, self::UNSUPPORTED];

    private function __construct()
    {
    }
}
