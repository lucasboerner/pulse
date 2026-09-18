<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CheckRollup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CheckRollup>
 */
class CheckRollupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckRollup::class);
    }
}
