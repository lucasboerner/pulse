<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Doctrine\UuidV7Generator;
use Ramsey\Uuid\UuidInterface;

trait EntityIdTrait
{
    // UUID v7 (time-ordered): the leading millisecond timestamp makes inserts
    // append to the right edge of the primary-key B-tree instead of landing at
    // random leaves like v4 — fewer page splits, less index fragmentation, better
    // write locality. Still a standard 128-bit UUID stored in Postgres' native
    // `uuid` column, whose byte-order comparison sorts v7 values chronologically,
    // so the sequential-insert benefit holds at the index level.
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidV7Generator::class)]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidInterface $id = null;

    public function getId(): ?string
    {
        return $this->id?->toString();
    }
}
