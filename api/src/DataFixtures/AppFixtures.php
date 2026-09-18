<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Dev/test seed data. Load with:
 *   docker compose exec api bin/console doctrine:fixtures:load
 *
 * Prefer building objects through Zenstruck Foundry factories (tests/Factory/)
 * so the same defaults back both the fixtures and the test suite.
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $manager->flush();
    }
}
