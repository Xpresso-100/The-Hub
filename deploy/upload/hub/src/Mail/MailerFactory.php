<?php
declare(strict_types=1);

namespace Hub\Mail;

use Hub\Config;

/** Picks the mail transport from MAIL_TRANSPORT (SPEC s2.2). */
final class MailerFactory
{
    public static function create(): Mailer
    {
        $from = Config::get('MAIL_FROM');
        $transport = strtolower(trim(Config::get('MAIL_TRANSPORT')));
        if ($transport === 'log') {
            return new LogMailer(Config::path('MAIL_LOG_PATH'), $from);
        }
        if ($transport === 'mail') {
            return new PhpMailMailer($from);
        }
        throw new \RuntimeException('MAIL_TRANSPORT must be mail or log');
    }
}
