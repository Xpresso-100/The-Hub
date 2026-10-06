<?php
declare(strict_types=1);

namespace Hub\Mail;

/** Rejects empty/invalid recipients and header injection before any transport runs. */
final class MailGuard
{
    public static function check(string $to, string $subject): void
    {
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Recipient address is missing or not valid');
        }
        if (preg_match('/[\r\n]/', $to . $subject) === 1) {
            throw new \RuntimeException('Line breaks are not allowed in the recipient or subject');
        }
    }
}
