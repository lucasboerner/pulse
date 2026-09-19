<?php

declare(strict_types=1);

namespace App\Entity;

use App\ApiResource\IncidentResource;
use App\Enum\IncidentSeverity;
use App\Repository\IncidentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * One outage. An incident opens on the first failed check and closes on the first
 * successful one. The Ongoing, Degraded and Resolved labels are derived from
 * ended_at and severity, never stored: ended_at null with severity down is Ongoing,
 * ended_at null with degraded is Degraded, otherwise Resolved.
 *
 * The public shape is App\ApiResource\IncidentResource, read-only. The #[Map] here
 * drives the read direction (entity to resource); there is no write direction.
 */
#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Table(name: 'incident')]
#[ORM\Index(name: 'idx_incident_monitor_open', columns: ['monitor_id', 'ended_at'])]
#[ORM\Index(name: 'idx_incident_started', columns: ['started_at'])]
#[Map(target: IncidentResource::class)]
class Incident
{
    use EntityIdTrait;
    use TimestampableEntity;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitor_id', nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: IncidentSeverity::class)]
    private IncidentSeverity $severity;

    /**
     * Error message or status code of the opening check, copied so the incident
     * outlives the raw-result retention window.
     */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $cause = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedOpenedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedResolvedAt = null;

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function setMonitor(Monitor $monitor): self
    {
        $this->monitor = $monitor;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): self
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getSeverity(): IncidentSeverity
    {
        return $this->severity;
    }

    public function setSeverity(IncidentSeverity $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function getCause(): ?string
    {
        return $this->cause;
    }

    public function setCause(?string $cause): self
    {
        $this->cause = $cause;

        return $this;
    }

    public function getNotifiedOpenedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedOpenedAt;
    }

    public function setNotifiedOpenedAt(?\DateTimeImmutable $notifiedOpenedAt): self
    {
        $this->notifiedOpenedAt = $notifiedOpenedAt;

        return $this;
    }

    public function getNotifiedResolvedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedResolvedAt;
    }

    public function setNotifiedResolvedAt(?\DateTimeImmutable $notifiedResolvedAt): self
    {
        $this->notifiedResolvedAt = $notifiedResolvedAt;

        return $this;
    }
}
