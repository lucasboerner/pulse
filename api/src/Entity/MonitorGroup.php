<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitorGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * A flat label for the dashboard. Groups are labels: no colour, position or
 * settings; the dashboard sorts them by name.
 */
#[ORM\Entity(repositoryClass: MonitorGroupRepository::class)]
#[ORM\Table(name: 'monitor_group')]
#[ORM\UniqueConstraint(name: 'uniq_monitor_group_name', columns: ['name'])]
class MonitorGroup
{
    use EntityIdTrait;
    use TimestampableEntity;

    #[ORM\Column(type: Types::STRING, length: 80, unique: true)]
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
