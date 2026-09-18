<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CheckRollupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * One monitor-hour, aggregated. Raw results expire after seven days, so these
 * counts and latency figures are the permanent record. The bucket is
 * [bucket_start, +1 hour); the unique key includes region from day one so
 * distributed probes add rows instead of reshaping the key.
 */
#[ORM\Entity(repositoryClass: CheckRollupRepository::class)]
#[ORM\Table(name: 'check_rollup')]
#[ORM\UniqueConstraint(name: 'uniq_check_rollup_bucket', columns: ['monitor_id', 'bucket_start', 'region'])]
class CheckRollup
{
    use EntityIdTrait;
    use TimestampableEntity;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitor_id', nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $bucketStart;

    /**
     * Reserved, and part of the unique key from day one.
     */
    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'local'])]
    private string $region = 'local';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $upCount = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $degradedCount = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $downCount = 0;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $latencyMinMs = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $latencyAvgMs = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $latencyMaxMs = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $latencyP95Ms = null;

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function setMonitor(Monitor $monitor): self
    {
        $this->monitor = $monitor;

        return $this;
    }

    public function getBucketStart(): \DateTimeImmutable
    {
        return $this->bucketStart;
    }

    public function setBucketStart(\DateTimeImmutable $bucketStart): self
    {
        $this->bucketStart = $bucketStart;

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

    public function getUpCount(): int
    {
        return $this->upCount;
    }

    public function setUpCount(int $upCount): self
    {
        $this->upCount = $upCount;

        return $this;
    }

    public function getDegradedCount(): int
    {
        return $this->degradedCount;
    }

    public function setDegradedCount(int $degradedCount): self
    {
        $this->degradedCount = $degradedCount;

        return $this;
    }

    public function getDownCount(): int
    {
        return $this->downCount;
    }

    public function setDownCount(int $downCount): self
    {
        $this->downCount = $downCount;

        return $this;
    }

    public function getLatencyMinMs(): ?int
    {
        return $this->latencyMinMs;
    }

    public function setLatencyMinMs(?int $latencyMinMs): self
    {
        $this->latencyMinMs = $latencyMinMs;

        return $this;
    }

    public function getLatencyAvgMs(): ?int
    {
        return $this->latencyAvgMs;
    }

    public function setLatencyAvgMs(?int $latencyAvgMs): self
    {
        $this->latencyAvgMs = $latencyAvgMs;

        return $this;
    }

    public function getLatencyMaxMs(): ?int
    {
        return $this->latencyMaxMs;
    }

    public function setLatencyMaxMs(?int $latencyMaxMs): self
    {
        $this->latencyMaxMs = $latencyMaxMs;

        return $this;
    }

    public function getLatencyP95Ms(): ?int
    {
        return $this->latencyP95Ms;
    }

    public function setLatencyP95Ms(?int $latencyP95Ms): self
    {
        $this->latencyP95Ms = $latencyP95Ms;

        return $this;
    }
}
