<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CheckStatus;
use App\Repository\CheckResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One raw check. No Gedmo and no soft delete: retention hard-deletes this table,
 * a soft-delete filter would be appended to every range scan, and checked_at is
 * the time axis, so a Gedmo created_at would only duplicate it.
 */
#[ORM\Entity(repositoryClass: CheckResultRepository::class)]
#[ORM\Table(name: 'check_result')]
#[ORM\Index(name: 'idx_check_result_monitor_time', columns: ['monitor_id', 'checked_at'])]
#[ORM\Index(name: 'idx_check_result_checked_at', columns: ['checked_at'])]
class CheckResult
{
    use EntityIdTrait;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitor_id', nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: CheckStatus::class)]
    private CheckStatus $status;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $latencyMs = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $httpStatusCode = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    /**
     * Reserved: which probe produced the row.
     */
    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'local'])]
    private string $region = 'local';

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function setMonitor(Monitor $monitor): self
    {
        $this->monitor = $monitor;

        return $this;
    }

    public function getStatus(): CheckStatus
    {
        return $this->status;
    }

    public function setStatus(CheckStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function setLatencyMs(?int $latencyMs): self
    {
        $this->latencyMs = $latencyMs;

        return $this;
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode(?int $httpStatusCode): self
    {
        $this->httpStatusCode = $httpStatusCode;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(\DateTimeImmutable $checkedAt): self
    {
        $this->checkedAt = $checkedAt;

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
}
