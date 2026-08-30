<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\user\User;

/**
 * External verification email (PIN or secure link) — sent through PKP's
 * own Mailable/Mail::send() so delivery goes through the journal's actual
 * configured mail transport, exactly like every other OJS system email
 * (verified against pkp-lib stable-3_5_0
 * api/v1/users/PKPUserController.php's own `Mail::send($mailable)` call).
 *
 * Deliberately does not use the full EmailTemplate/Mailable
 * variable-substitution framework (Recipient/Configurable traits, an
 * admin-editable EmailTemplate DB row) — content is a fixed, localized
 * string built via `__()`, not yet customizable via Journal Setup >
 * Emails. That is a scope decision, not an oversight; see
 * docs/v2/TASKLIST.md's IDN-007 note.
 *
 * One major privacy point: never includes a manuscript title, submission
 * ID, or any resource detail — verification proves the OJS account only;
 * resource relationship is established separately and afterward
 * (submissionVerify). The body text is deliberately generic regardless of
 * the verification `purpose`.
 */
final class SupportVerificationMailable extends Mailable
{
    public function __construct(Context $context, User $recipient, string $subjectText, string $bodyHtml)
    {
        parent::__construct([]);

        $fromEmail = (string) $context->getData('contactEmail');
        $fromName = (string) $context->getData('contactName');
        if ($fromEmail !== '') {
            $this->from($fromEmail, $fromName !== '' ? $fromName : null);
        }

        $this->to($recipient->getEmail(), $recipient->getFullName());
        $this->subject($subjectText);
        $this->body($bodyHtml);
    }
}
