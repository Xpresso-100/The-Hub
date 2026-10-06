<?php
declare(strict_types=1);

namespace Hub\Mail;

use Hub\Clock;

/** MAIL_TRANSPORT=log: appends one JSON line {to, from, subject, body, at} per message. */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $path, private readonly string $from)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        MailGuard::check($to, $subject);
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Mail log folder cannot be created');
        }
        $line = json_encode([
            'to' => $to,
            'from' => $this->from,
            'subject' => $subject,
            'body' => $body,
            'at' => Clock::now()->format(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (@file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Mail log cannot be written');
        }
    }
}
