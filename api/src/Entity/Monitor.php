<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\CheckStatus;
use App\Enum\MonitorType;
use App\Repository\MonitorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A target and its schedule. The central entity: the type enum, not a subclass,
 * carries the kind of check, so later types (domain expiry, TLS, DNS) are new enum
 * cases and rows on this table rather than a rewrite.
 *
 * Soft-deleted (Gedmo): deleting a monitor hides it from every read while its
 * history stays. There is deliberately no failure counter — an incident opens on
 * the first failure, so there is nothing to count.
 *
 * The GetCollection operation here is the minimal authenticated read this phase
 * verifies. Phase 2 replaces it with the full operation set, validation and its own
 * state provider and processor.
 */
#[ORM\Entity(repositoryClass: MonitorRepository::class)]
#[ORM\Table(name: 'monitor')]
#[ORM\Index(name: 'idx_monitor_due', columns: ['enabled', 'next_check_at'])]
#[ORM\Index(name: 'idx_monitor_group', columns: ['monitor_group_id'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt')]
#[ApiResource(
    shortName: 'Monitor',
    operations: [new GetCollection()],
    normalizationContext: ['groups' => ['monitor:read']],
)]
class Monitor
{
    use EntityIdTrait;
    use TimestampableEntity;
    use SoftDeleteableEntity;

    #[ORM\Column(type: Types::STRING, length: 120)]
    #[Groups(['monitor:read'])]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    #[Groups(['monitor:read'])]
    private string $url;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: MonitorType::class, options: ['default' => MonitorType::Http->value])]
    #[Groups(['monitor:read'])]
    private MonitorType $type = MonitorType::Http;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 60])]
    #[Groups(['monitor:read'])]
    private int $intervalSeconds = 60;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 8000])]
    #[Groups(['monitor:read'])]
    private int $timeoutMs = 8000;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['monitor:read'])]
    private ?int $expectedStatusCode = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['monitor:read'])]
    private bool $enabled = true;

    /**
     * Reserved for distributed probes: one value, nothing reads it yet.
     */
    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'local'])]
    #[Groups(['monitor:read'])]
    private string $region = 'local';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups(['monitor:read'])]
    private \DateTimeImmutable $nextCheckAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[Groups(['monitor:read'])]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    /**
     * Denormalised last outcome so the dashboard list is one query instead of a
     * latest-row lookup per monitor.
     */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: CheckStatus::class, nullable: true)]
    #[Groups(['monitor:read'])]
    private ?CheckStatus $lastStatus = null;

    #[ORM\ManyToOne(targetEntity: MonitorGroup::class)]
    #[ORM\JoinColumn(name: 'monitor_group_id', nullable: true, onDelete: 'SET NULL')]
    private ?MonitorGroup $group = null;

    /**
     * The alert recipient list. Owned here: alert mail goes to these rows and
     * nowhere else. A join table, not an entity — no identifier, no timestamps.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'monitor_subscriber')]
    #[ORM\JoinColumn(name: 'monitor_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private Collection $subscribers;

    public function __construct()
    {
        $this->nextCheckAt = new \DateTimeImmutable();
        $this->subscribers = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getType(): MonitorType
    {
        return $this->type;
    }

    public function setType(MonitorType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function setIntervalSeconds(int $intervalSeconds): self
    {
        $this->intervalSeconds = $intervalSeconds;

        return $this;
    }

    public function getTimeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function setTimeoutMs(int $timeoutMs): self
    {
        $this->timeoutMs = $timeoutMs;

        return $this;
    }

    public function getExpectedStatusCode(): ?int
    {
        return $this->expectedStatusCode;
    }

    public function setExpectedStatusCode(?int $expectedStatusCode): self
    {
        $this->expectedStatusCode = $expectedStatusCode;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): self
    {
        $this->region = $region;

        return $this;
    }

    public function getNextCheckAt(): \DateTimeImmutable
    {
        return $this->nextCheckAt;
    }

    public function setNextCheckAt(\DateTimeImmutable $nextCheckAt): self
    {
        $this->nextCheckAt = $nextCheckAt;

        return $this;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): self
    {
        $this->lastCheckedAt = $lastCheckedAt;

        return $this;
    }

    public function getLastStatus(): ?CheckStatus
    {
        return $this->lastStatus;
    }

    public function setLastStatus(?CheckStatus $lastStatus): self
    {
        $this->lastStatus = $lastStatus;

        return $this;
    }

    public function getGroup(): ?MonitorGroup
    {
        return $this->group;
    }

    public function setGroup(?MonitorGroup $group): self
    {
        $this->group = $group;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getSubscribers(): Collection
    {
        return $this->subscribers;
    }

    public function addSubscriber(User $user): self
    {
        if (false === $this->subscribers->contains($user)) {
            $this->subscribers->add($user);
        }

        return $this;
    }

    public function removeSubscriber(User $user): self
    {
        $this->subscribers->removeElement($user);

        return $this;
    }
}
