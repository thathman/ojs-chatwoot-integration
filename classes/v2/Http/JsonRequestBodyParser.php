<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

/**
 * Bridges a JSON request body into `$_POST` so `$request->getUserVar()`
 * (PKPRequest::getUserVars() merges only `$_GET`/`$_POST` — verified
 * against pkp-lib stable-3_5_0 `classes/core/PKPRequest.php`) sees the
 * same fields a form-encoded POST would have produced.
 *
 * This exists because Chatwoot's own Custom Tool HTTP client always sets
 * `Content-Type: application/json` whenever it sends a request body
 * (verified against `chatwoot/chatwoot` `develop`
 * `enterprise/lib/captain/tools/http_tool.rb`'s `request_headers()` —
 * `headers['Content-Type'] = 'application/json' if json_body.present?`,
 * unconditional, no opt-out). Without this bridge, every Support API
 * endpoint a provisioned Custom Tool calls would see every field as
 * missing, since PHP never populates `$_POST` for a raw JSON body.
 *
 * Deliberately never overwrites an existing `$_POST` key (a real
 * form-encoded submission always wins) and only ever reads the request
 * body once per process.
 */
final class JsonRequestBodyParser
{
    private static bool $applied = false;

    /**
     * @param callable(): string|false $rawBodyReader Injectable for tests —
     *   PHP's CLI SAPI never exposes a real `php://input` stream (it only
     *   carries the request body under an actual web SAPI), so this is the
     *   only way to exercise the real merge logic outside of a live server.
     */
    public static function mergeIntoPostOnce(?callable $rawBodyReader = null): void
    {
        if (self::$applied) {
            return;
        }
        self::$applied = true;

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos((string) $contentType, 'application/json') === false) {
            return;
        }

        $rawBodyReader ??= static fn (): string|false => file_get_contents('php://input');
        $raw = $rawBodyReader();
        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $key => $value) {
            if (is_string($key) && !array_key_exists($key, $_POST) && (is_scalar($value) || $value === null)) {
                $_POST[$key] = $value;
            }
        }
    }

    /** Test-only: allows a fresh test to exercise mergeIntoPostOnce() again within the same process. */
    public static function resetForTests(): void
    {
        self::$applied = false;
    }
}
