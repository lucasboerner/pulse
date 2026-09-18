<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CreateUserCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCreatesOperatorThenRejectsDuplicateUsername(): void
    {
        self::bootKernel();
        $command = (new Application(self::$kernel))->find('app:user:create');

        $first = new CommandTester($command);
        $first->setInputs(['alice', 'alice@example.com', 'secret', 'secret']);
        $this->assertSame(Command::SUCCESS, $first->execute([]));
        $this->assertCount(1, UserFactory::repository()->findAll());

        $second = new CommandTester($command);
        $second->setInputs(['alice', 'other@example.com', 'secret', 'secret']);
        $this->assertSame(Command::FAILURE, $second->execute([]));
        $this->assertCount(1, UserFactory::repository()->findAll());
    }
}
