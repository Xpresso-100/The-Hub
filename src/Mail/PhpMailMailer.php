<?php
declare(strict_types=1);

namespace Hub\Mail;

/** MAIL_TRANSPORT=mail: PHP mail(), the channel the reference uses (submit_order.php:201). */
final class PhpMailMailer implements Mailer
{
    public function __construct(private readonly string $from)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        MailGuard::check($to, $subject);
        $headers = [
            'From' => $this->from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
        ];
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        if (!@mail($to, $encodedSubject, $body, $headers)) {
            throw new \RuntimeException('mail() did not accept the message');
        }
    }
}
