<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mailer whose transport always fails, standing in for the blocked-SMTP case the
 * mail-test command must report faithfully. Its message is the "real reason" the
 * command is expected to print instead of a generic one.
 */
final class ThrowingMailer implements MailerInterface
{
    public const string REASON = 'Connection could not be established with host "smtp://blocked:2525": php_network_getaddresses failed.';

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new TransportException(self::REASON);
    }
}
