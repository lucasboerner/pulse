<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\CheckStatus;
use App\Enum\MonitorType;
use App\Repository\MonitorRepository;
use App\State\MonitorCollectionProvider;
use App\State\MonitorCreateProcessor;
use App\State\MonitorDeleteProcessor;
use App\State\MonitorItemProvider;
use App\State\MonitorUpdateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A target and its schedule. The central entity: the type enum, not a subclass,
 * carries the kind of check, so later types (domain expiry, TLS, DNS) are new enum
 * cases and rows on this table rather than a rewrite.
 *
 * Soft-deleted (Gedmo): deleting a monitor hides it from every read while its
 * history stays. There is deliberately no failure counter — an incident opens on
 * the first failure, so there is nothing to count.
 *
 * Reads exclude soft-deleted rows through the global Gedmo query filter; writes
 * go through one state class per operation under App\State. The read group is on
 * every property; the write group is only on the columns a client may set — the
 * check pipeline owns nextCheckAt, lastCheckedAt, lastStatus and region.
 *
 * The url/type pair is unique at the application level only: a database index
 * would also count soft-deleted rows and block re-adding a deleted target, whereas
 * the repository query behind UniqueEntity skips them through the same filter.
 */
#[ORM\Entity(repositoryClass: MonitorRepository::class)]
#[ORM\Table(name: 'monitor')]
#[ORM\Index(name: 'idx_monitor_due', columns: ['enabled', 'next_check_at'])]
#[ORM\Index(name: 'idx_monitor_group', columns: ['monitor_group_id'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt')]
#[ApiResource(
    shortName: 'Monitor',
    operations: [
        new GetCollection(provider: MonitorCollectionProvider::class),
        new Get(provider: MonitorItemProvider::class),
        new Post(processor: MonitorCreateProcessor::class),
        new Patch(processor: MonitorUpdateProcessor::class),
        new Delete(processor: MonitorDeleteProcessor::class),
    ],
    normalizationContext: ['groups' => ['monitor:read']],
    denormalizationContext: ['groups' => ['monitor:write']],
)]
#[UniqueEntity(
    fields: ['url', 'type'],
    errorPath: 'url',
    message: 'A monitor with this URL and type already exists.',
)]
class Monitor
{
    use EntityIdTrait;
    use TimestampableEntity;
    use SoftDeleteableEntity;

    #[ORM\Column(type: Types::STRING, length: 120)]
    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\NotBlank]
    #[Assert\Url]
    #[Assert\Length(max: 2048)]
    private string $url;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: MonitorType::class, options: ['default' => MonitorType::Http->value])]
    #[Groups(['monitor:read', 'monitor:write'])]
    private MonitorType $type = MonitorType::Http;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 60])]
    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\GreaterThanOrEqual(15)]
    private int $intervalSeconds = 60;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 8000])]
    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\Positive]
    private int $timeoutMs = 8000;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\Range(min: 100, max: 599)]
    private ?int $expectedStatusCode = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['monitor:read', 'monitor:write'])]
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
    #[Groups(['monitor:read', 'monitor:write'])]
    private ?MonitorGroup $monitorGroup = null;

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
    #[Groups(['monitor:read', 'monitor:write'])]
    private Collection $subscribers;

    public function __construct()
    {
        $this->nextCheckAt = new \DateTimeImmutable();
        $this->subscribers = new ArrayCollection();
    }

    /**
     * A check may not outlive its own interval: the timeout, in milliseconds, must
     * fit inside intervalSeconds. Expressed as a callback because it spans two
     * fields, and reported against timeoutMs so the 422 names the field at fault.
     */
    #[Assert\Callback]
    public function validateTimeoutWithinInterval(ExecutionContextInterface $context): void
    {
        if ($this->timeoutMs > $this->intervalSeconds * 1000) {
            $context->buildViolation('The timeout must not exceed the interval (interval_seconds × 1000 milliseconds).')
                ->atPath('timeoutMs')
                ->addViolation();
        }
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

    public function getMonitorGroup(): ?MonitorGroup
    {
        return $this->monitorGroup;
    }

    public function setMonitorGroup(?MonitorGroup $monitorGroup): self
    {
        $this->monitorGroup = $monitorGroup;

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
