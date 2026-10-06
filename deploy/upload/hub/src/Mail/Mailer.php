<?php
declare(strict_types=1);

namespace Hub\Mail;

/** Outgoing plain-text mail (SPEC s11). Get an instance from MailerFactory::create(). */
interface Mailer
{
    /** @throws \RuntimeException when the message could not be handed off; the message says why. */
    public function send(string $to, string $subject, string $body): void;
}
