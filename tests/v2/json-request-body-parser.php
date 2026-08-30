<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Http\JsonRequestBodyParser;

function jsonBodyParserCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// A Chatwoot Custom Tool JSON POST — verified against the real
// enterprise/lib/captain/tools/http_tool.rb behavior: Content-Type is
// always application/json whenever a body is present, no opt-out — so a
// provisioned tool's request body reaches OJS exactly like this.
// ================================================================
$_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';
$_POST = ['formField' => 'must-survive'];

$jsonBody = json_encode([
    'chatwootAccountId' => '1',
    'chatwootContactId' => '100',
    'chatwootConversationId' => '500',
    'submissionId' => 42,
    'nested' => ['should' => 'be ignored'],
]);

JsonRequestBodyParser::mergeIntoPostOnce(fn (): string => (string) $jsonBody);

jsonBodyParserCheck($_POST['formField'] === 'must-survive', 'an existing $_POST value must never be overwritten by the JSON body');
jsonBodyParserCheck($_POST['chatwootAccountId'] === '1', 'a JSON body field must populate $_POST so getUserVar() can see it');
jsonBodyParserCheck($_POST['chatwootConversationId'] === '500', 'every scalar JSON body field must be merged');
jsonBodyParserCheck($_POST['submissionId'] === 42, 'a numeric JSON field must merge with its native type intact');
jsonBodyParserCheck(!array_key_exists('nested', $_POST), 'a non-scalar JSON field must never be merged into $_POST');

// ================================================================
// Single-parse guarantee: a second call in the same process must be a no-op,
// even if given a different body, since php://input can only be read once.
// ================================================================
$beforePost = $_POST;
JsonRequestBodyParser::mergeIntoPostOnce(fn (): string => json_encode(['shouldNeverAppear' => 'x']));
jsonBodyParserCheck($_POST === $beforePost, 'a second call in the same process must never re-parse the body');
jsonBodyParserCheck(!array_key_exists('shouldNeverAppear', $_POST), 'a field from a second, ignored call must never leak into $_POST');

// ================================================================
// A non-JSON Content-Type must never read/parse a body at all.
// ================================================================
JsonRequestBodyParser::resetForTests();
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_POST = ['a' => 'b'];
$readerCalled = false;
JsonRequestBodyParser::mergeIntoPostOnce(function () use (&$readerCalled): string {
    $readerCalled = true;
    return '{}';
});
jsonBodyParserCheck(!$readerCalled, 'a non-JSON Content-Type must never even attempt to read the body');
jsonBodyParserCheck($_POST === ['a' => 'b'], 'a non-JSON request must leave $_POST completely untouched');

// ================================================================
// Malformed JSON must never throw or corrupt $_POST.
// ================================================================
JsonRequestBodyParser::resetForTests();
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_POST = ['keep' => 'me'];
JsonRequestBodyParser::mergeIntoPostOnce(fn (): string => 'not { valid json');
jsonBodyParserCheck($_POST === ['keep' => 'me'], 'malformed JSON must leave $_POST untouched rather than throwing or partially merging');

fwrite(STDOUT, "JSON request body parser tests passed\n");
