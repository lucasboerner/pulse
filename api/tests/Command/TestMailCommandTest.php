<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TestMailCommand;
use App\Tests\Double\ThrowingMailer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The mail-test command sends through the configured transport and reports which one
 * carried the message; a failing transport makes it exit non-zero with the
 * transport's own reason, never a generic message — because outbound SMTP is blocked
 * by default on many hosts and the operator needs the real cause.
 */
final class TestMailCommandTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    public function testASuccessfulSendReportsTheTransportAndTheRecipient(): void
    {
        self::bootKernel();
        $command = (new Application(self::$kernel))->find('app:mail:test');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['recipient' => 'someone@example.test']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertEmailCount(1);
        $display = $tester->getDisplay();
        self::assertStringContainsString('someone@example.test', $display);
        self::assertStringContainsString('null://null', $display);
    }

    public function testAFailingTransportExitsNonZeroWithTheRealReason(): void
    {
        $command = new TestMailCommand(new ThrowingMailer(), 'smtp://blocked:2525');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['recipient' => 'someone@example.test']);

        self::assertSame(Command::FAILURE, $exitCode);
        // The console wraps the error block across lines; collapse whitespace so the
        // whole transport reason can be matched as one contiguous string.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString(ThrowingMailer::REASON, $display);
    }
}
