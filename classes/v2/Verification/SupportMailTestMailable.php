<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\user\User;

/**
 * ADM-006: the admin "Send test email" diagnostic — a dedicated Mailable
 * deliberately independent of the real verification challenge system
 * (SupportVerificationMailable), so a future change to either can never
 * accidentally affect the other. Sent through the same real
 * Mailable/Mail::send() transport path every other OJS system email
 * uses (same pattern as SupportVerificationMailable — see that class's
 * own docblock for the pkp-lib precedent).
 *
 * Proves only that OJS successfully handed a message to the configured
 * mail transport — never a claim of actual inbox delivery, which this
 * codebase has no visibility into (see AccountDiagnosticEngine's own
 * "this codebase has no visibility into email delivery" docblock note).
 */
final class SupportMailTestMailable extends Mailable
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
