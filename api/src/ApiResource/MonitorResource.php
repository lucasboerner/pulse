<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Monitor;
use App\Entity\MonitorGroup;
use App\Entity\User;
use App\Enum\CheckStatus;
use App\Enum\MonitorType;
use App\State\MonitorCreateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The public shape of a monitor. A DTO over the Monitor entity: the Object Mapper
 * maps the two by matching property names, so the API contract stays decoupled from
 * the schema. Read-only fields carry #[Map(if: false)] so a write never maps them
 * back onto the entity — the check pipeline owns them.
 */
#[ApiResource(
    shortName: 'Monitor',
    operations: [
        new GetCollection(stateOptions: new Options(entityClass: Monitor::class)),
        new Get(stateOptions: new Options(entityClass: Monitor::class)),
        new Post(
            processor: MonitorCreateProcessor::class,
            validationContext: ['groups' => ['Default', 'monitor:create']],
            stateOptions: new Options(entityClass: Monitor::class),
        ),
        new Patch(stateOptions: new Options(entityClass: Monitor::class)),
        new Delete(stateOptions: new Options(entityClass: Monitor::class)),
    ],
    normalizationContext: ['groups' => ['monitor:read']],
    denormalizationContext: ['groups' => ['monitor:write']],
)]
#[UniqueEntity(
    fields: ['url', 'type'],
    errorPath: 'url',
    entityClass: Monitor::class,
    message: 'A monitor with this URL and type already exists.',
    groups: ['monitor:create'],
)]
#[Map(target: Monitor::class)]
class MonitorResource
{
    #[ApiProperty(identifier: true)]
    #[Map(if: false)]
    public ?string $id = null;

    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $name = '';

    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\NotBlank]
    #[Assert\Url]
    #[Assert\Length(max: 2048)]
    public string $url = '';

    #[Groups(['monitor:read', 'monitor:write'])]
    public MonitorType $type = MonitorType::Http;

    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\GreaterThanOrEqual(15)]
    public int $intervalSeconds = 60;

    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\GreaterThanOrEqual(15)]
    public ?int $downIntervalSeconds = null;

    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\Positive]
    public int $timeoutMs = 8000;

    #[Groups(['monitor:read', 'monitor:write'])]
    #[Assert\Range(min: 100, max: 599)]
    public ?int $expectedStatusCode = null;

    #[Groups(['monitor:read', 'monitor:write'])]
    public bool $enabled = true;

    #[Groups(['monitor:read'])]
    #[Map(if: false)]
    public string $region = 'local';

    #[Groups(['monitor:read'])]
    #[Map(if: false)]
    public ?\DateTimeImmutable $nextCheckAt = null;

    #[Groups(['monitor:read'])]
    #[Map(if: false)]
    public ?\DateTimeImmutable $lastCheckedAt = null;

    #[Groups(['monitor:read'])]
    #[Map(if: false)]
    public ?CheckStatus $lastStatus = null;

    #[Groups(['monitor:read', 'monitor:write'])]
    public ?MonitorGroup $monitorGroup = null;

    /**
     * The alert recipient list, referencing existing operators. Modelled with an
     * adder and remover so the serializer and the Object Mapper both replace the
     * set through PropertyAccess rather than assigning an array to a Collection.
     *
     * @var Collection<int, User>
     */
    #[Groups(['monitor:read', 'monitor:write'])]
    private Collection $subscribers;

    public function __construct()
    {
        $this->subscribers = new ArrayCollection();
    }

    /**
     * A check may not outlive its own interval: the timeout, in milliseconds, must
     * fit inside intervalSeconds, and inside downIntervalSeconds when one is set.
     * Expressed as a callback because it spans fields, and reported against
     * timeoutMs so the 422 names the field at fault.
     */
    #[Assert\Callback]
    public function validateTimeoutWithinInterval(ExecutionContextInterface $context): void
    {
        if ($this->timeoutMs > $this->intervalSeconds * 1000) {
            $context->buildViolation('The timeout must not exceed the interval (interval_seconds × 1000 milliseconds).')
                ->atPath('timeoutMs')
                ->addViolation();

            return;
        }

        if (null !== $this->downIntervalSeconds && $this->timeoutMs > $this->downIntervalSeconds * 1000) {
            $context->buildViolation('The timeout must not exceed the interval while down (down_interval_seconds × 1000 milliseconds).')
                ->atPath('timeoutMs')
                ->addViolation();
        }
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
