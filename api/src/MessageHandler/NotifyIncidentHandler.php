<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Incident;
use App\Entity\Monitor;
use App\Incident\IncidentTransitionKind;
use App\Message\NotifyIncident;
use App\Repository\IncidentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Mails the monitor's subscribers about one incident transition. Idempotent by
 * design: it checks the matching notified_*_at stamp before it does anything, so a
 * Messenger retry after a delivered mail sends nothing. Recipients come from the
 * monitor's own subscriber list and nowhere else — a monitor with none still opens
 * and closes incidents, it simply sends no mail.
 *
 * Stamping happens after a successful send: a send that throws is retried with
 * nothing stamped, and a transport that fails after delivering can duplicate a mail
 * once. That is the right side to err on — a duplicate alert beats a missed outage.
 */
#[AsMessageHandler]
final readonly class NotifyIncidentHandler
{
    public function __construct(
        private IncidentRepository $incidents,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function __invoke(NotifyIncident $message): void
    {
        $incident = $this->incidents->find($message->incidentId);

        if (null === $incident) {
            $this->logger->debug('Skipped a notification for an incident that no longer exists.', [
                'incident' => $message->incidentId,
            ]);

            return;
        }

        if (true === $this->alreadyNotified($incident, $message->kind)) {
            return;
        }

        $monitor = $incident->getMonitor();
        $recipients = $this->recipients($monitor);

        if ([] === $recipients) {
            $this->logger->info('An incident transition has no subscribers to notify; stamping it and sending nothing.', [
                'incident' => $message->incidentId,
                'monitor' => (string) $monitor->getId(),
                'transition' => $message->kind->value,
            ]);
            $this->stamp($incident, $message->kind);
            $this->entityManager->flush();

            return;
        }

        $this->mailer->send($this->email($message->kind, $incident, $monitor, $recipients));

        $this->stamp($incident, $message->kind);
        $this->entityManager->flush();
    }

    private function alreadyNotified(Incident $incident, IncidentTransitionKind $kind): bool
    {
        return match ($kind) {
            IncidentTransitionKind::Opened => null !== $incident->getNotifiedOpenedAt(),
            IncidentTransitionKind::Resolved => null !== $incident->getNotifiedResolvedAt(),
        };
    }

    private function stamp(Incident $incident, IncidentTransitionKind $kind): void
    {
        $now = new \DateTimeImmutable();

        if (IncidentTransitionKind::Opened === $kind) {
            $incident->setNotifiedOpenedAt($now);

            return;
        }

        $incident->setNotifiedResolvedAt($now);
    }

    /**
     * @return list<string>
     */
    private function recipients(Monitor $monitor): array
    {
        $emails = [];
        foreach ($monitor->getSubscribers() as $subscriber) {
            $emails[] = $subscriber->getEmail();
        }

        return $emails;
    }

    /**
     * @param list<string> $recipients
     */
    private function email(IncidentTransitionKind $kind, Incident $incident, Monitor $monitor, array $recipients): TemplatedEmail
    {
        $link = rtrim($this->frontendUrl, '/').'/monitors/'.(string) $monitor->getId();

        $email = (new TemplatedEmail())
            ->to(...$recipients);

        if (IncidentTransitionKind::Opened === $kind) {
            return $email
                ->subject(\sprintf('[Pulse] %s is down', $monitor->getName()))
                ->htmlTemplate('mail/incident_opened.html.twig')
                ->textTemplate('mail/incident_opened.txt.twig')
                ->context([
                    'monitorName' => $monitor->getName(),
                    'monitorUrl' => $monitor->getUrl(),
                    'cause' => $incident->getCause(),
                    'startedAt' => $incident->getStartedAt(),
                    'link' => $link,
                ]);
        }

        return $email
            ->subject(\sprintf('[Pulse] %s is back up', $monitor->getName()))
            ->htmlTemplate('mail/incident_resolved.html.twig')
            ->textTemplate('mail/incident_resolved.txt.twig')
            ->context([
                'monitorName' => $monitor->getName(),
                'monitorUrl' => $monitor->getUrl(),
                'startedAt' => $incident->getStartedAt(),
                'endedAt' => $incident->getEndedAt(),
                'duration' => $this->duration($incident),
                'link' => $link,
            ]);
    }

    /**
     * A plain, phone-legible outage length from the incident's start and end — the
     * two largest non-zero units, down to seconds for a short blip.
     */
    private function duration(Incident $incident): string
    {
        $endedAt = $incident->getEndedAt();

        if (null === $endedAt) {
            return 'unknown';
        }

        $seconds = $endedAt->getTimestamp() - $incident->getStartedAt()->getTimestamp();

        if (0 >= $seconds) {
            return 'less than a second';
        }

        $units = [
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
            'second' => 1,
        ];

        $parts = [];
        foreach ($units as $label => $size) {
            $count = intdiv($seconds, $size);
            if (0 === $count) {
                continue;
            }
            $seconds -= $count * $size;
            $parts[] = \sprintf('%d %s%s', $count, $label, 1 === $count ? '' : 's');
            if (2 === \count($parts)) {
                break;
            }
        }

        return implode(' ', $parts);
    }
}
