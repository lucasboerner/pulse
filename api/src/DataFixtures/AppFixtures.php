<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Story\AppStory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Development and demonstration seed data. Load into an empty database with:
 *
 *   docker compose exec api bin/console doctrine:fixtures:load
 *
 * The fleet itself is defined in App\Story\AppStory (built through the Zenstruck
 * Foundry factories in tests/Factory/, so the same defaults back both the fixtures and
 * the test suite). This is the doctrine:fixtures:load entry point; it just loads that
 * story.
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        AppStory::load();
    }
}
