<?php

namespace App\Messaging;

use InvalidArgumentException;

final readonly class OutboundMessageRequest
{
    public function __construct(
        public string $tenantId,
        public string $senderEmail,
        public string $recipientEmail,
        public string $subject,
        public string $body,
        public ?string $senderName = null,
        public ?string $replyToEmail = null,
    ) {
        foreach ([$senderEmail, $recipientEmail, $replyToEmail] as $email) {
            if ($email !== null && (! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email))) {
                throw new InvalidArgumentException('An email address is invalid.');
            }
        }
        if (preg_match('/[\r\n]/', $subject) || strlen($subject) > 500) {
            throw new InvalidArgumentException('The email subject is invalid.');
        }
        if (($senderName !== null && (preg_match('/[\r\n]/', $senderName) || strlen($senderName) > 255))) {
            throw new InvalidArgumentException('The sender name is invalid.');
        }
        if (strlen($body) > 200_000) {
            throw new InvalidArgumentException('The email body exceeds the size limit.');
        }
    }
}
