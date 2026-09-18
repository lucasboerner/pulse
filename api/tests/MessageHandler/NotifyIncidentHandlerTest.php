<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Enum\IncidentSeverity;
use App\Incident\IncidentTransitionKind;
use App\Message\NotifyIncident;
use App\MessageHandler\NotifyIncidentHandler;
use App\Repository\IncidentRepository;
use App\Tests\Factory\IncidentFactory;
use App\Tests\Factory\MonitorFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Address;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The notifier mails every subscriber of the monitor and nobody else, stamps the
 * matching notified_*_at, and is idempotent: a second handling of the same
 * transition sends nothing. A monitor with no subscribers still stamps and sends
 * nothing. No transport is reached — the null transport discards while Symfony's
 * mailer assertions read the collected messages.
 */
final class NotifyIncidentHandlerTest extends KernelTestCase
{
    use Factories;
    use MailerAssertionsTrait;
    use ResetDatabase;

    public function testItMailsEverySubscriberAndStampsTheOpenedTransition(): void
    {
        self::bootKernel();
        $alice = UserFactory::createOne(['email' => 'alice@example.test']);
        $bob = UserFactory::createOne(['email' => 'bob@example.test']);
        $monitor = MonitorFactory::createOne(['name' => 'Checkout API', 'url' => 'https://checkout.example.test/health']);
        $monitor->addSubscriber($alice);
        $monitor->addSubscriber($bob);
        $this->entityManager()->flush();

        $incident = IncidentFactory::createOne([
            'monitor' => $monitor,
            'severity' => IncidentSeverity::Down,
            'cause' => 'Connection refused.',
            'startedAt' => new \DateTimeImmutable('2026-01-01 09:00:00'),
        ]);
        $incidentId = (string) $incident->getId();

        ($this->handler())(new NotifyIncident($incidentId, IncidentTransitionKind::Opened));

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        $addresses = array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo());
        self::assertEqualsCanonicalizing(['alice@example.test', 'bob@example.test'], $addresses);
        self::assertStringContainsString('Checkout API', $email->getTextBody());
        self::assertStringContainsString('Connection refused.', $email->getTextBody());
        self::assertStringContainsString('/monitors/'.(string) $monitor->getId(), $email->getTextBody());

        $this->entityManager()->clear();
        $reloaded = $this->incidents()->find($incidentId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getNotifiedOpenedAt());
    }

    public function testHandlingTheSameTransitionAgainSendsNothing(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $monitor->addSubscriber(UserFactory::createOne());
        $this->entityManager()->flush();
        $incidentId = (string) IncidentFactory::createOne(['monitor' => $monitor])->getId();

        ($this->handler())(new NotifyIncident($incidentId, IncidentTransitionKind::Opened));
        $this->entityManager()->clear();
        ($this->handler())(new NotifyIncident($incidentId, IncidentTransitionKind::Opened));

        self::assertEmailCount(1);
    }

    public function testAMonitorWithNoSubscribersSendsNothingButIsStamped(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $incidentId = (string) IncidentFactory::createOne(['monitor' => $monitor])->getId();

        ($this->handler())(new NotifyIncident($incidentId, IncidentTransitionKind::Opened));

        self::assertEmailCount(0);
        $this->entityManager()->clear();
        $reloaded = $this->incidents()->find($incidentId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getNotifiedOpenedAt());
    }

    public function testTheResolvedMailCarriesTheDurationAndTheDeepLink(): void
    {
        self::bootKernel();
        $monitor = MonitorFactory::createOne();
        $monitor->addSubscriber(UserFactory::createOne(['email' => 'ops@example.test']));
        $this->entityManager()->flush();

        $incident = IncidentFactory::createOne([
            'monitor' => $monitor,
            'startedAt' => new \DateTimeImmutable('2026-01-01 10:00:00'),
            'endedAt' => new \DateTimeImmutable('2026-01-01 12:30:00'),
        ]);
        $incidentId = (string) $incident->getId();

        ($this->handler())(new NotifyIncident($incidentId, IncidentTransitionKind::Resolved));

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertStringContainsString('2 hours 30 minutes', $email->getTextBody());
        self::assertStringContainsString('http://app.pulse.orb.local/monitors/'.(string) $monitor->getId(), $email->getTextBody());

        $this->entityManager()->clear();
        $reloaded = $this->incidents()->find($incidentId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getNotifiedResolvedAt());
    }

    private function handler(): NotifyIncidentHandler
    {
        $handler = self::getContainer()->get(NotifyIncidentHandler::class);
        \assert($handler instanceof NotifyIncidentHandler);

        return $handler;
    }

    private function incidents(): IncidentRepository
    {
        $incidents = self::getContainer()->get(IncidentRepository::class);
        \assert($incidents instanceof IncidentRepository);

        return $incidents;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
