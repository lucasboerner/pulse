<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Proves the mail path on a fresh install, before an outage does. Sends a short
 * message through the configured transport and reports which transport carried it.
 * Outbound SMTP is blocked by default on many hosting providers, so a failure prints
 * the transport's own reason — not a generic message — and exits non-zero, so the
 * operator sees the real cause.
 */
#[AsCommand(
    name: 'app:mail:test',
    description: 'Send a test message through the configured mail transport',
)]
final class TestMailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_DSN)%')]
        private readonly string $mailerDsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('recipient', InputArgument::REQUIRED, 'The e-mail address to send the test message to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipient = (string) $input->getArgument('recipient');
        $transport = $this->transport();

        $email = (new Email())
            ->to($recipient)
            ->subject('Pulse test message')
            ->text("This is a test message from Pulse.\n\nIf you are reading it, the configured mail transport works.");

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $io->error(\sprintf('Sending through %s failed: %s', $transport, $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Sent a test message to %s through %s.', $recipient, $transport));

        return Command::SUCCESS;
    }

    /**
     * The transport as scheme://host:port, with any credentials dropped — enough to
     * tell the operator which transport ran without printing a password.
     */
    private function transport(): string
    {
        $parts = parse_url($this->mailerDsn);

        if (false === \is_array($parts) || false === \array_key_exists('scheme', $parts)) {
            return 'the configured transport';
        }

        $transport = $parts['scheme'].'://'.($parts['host'] ?? '');

        if (true === \array_key_exists('port', $parts)) {
            $transport .= ':'.$parts['port'];
        }

        return $transport;
    }
}
