<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    /**
     * The plaintext behind the default password hash, for tests that log in.
     */
    public const string DEFAULT_PASSWORD = 'operator-password';

    public static function class(): string
    {
        return User::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // Hashed with native bcrypt at the lowest cost: Symfony's `auto` hasher
        // detects the $2y$ prefix and verifies it, so no hasher service is needed
        // here and the test suite stays fast.
        return [
            'username' => self::faker()->unique()->userName(),
            'email' => self::faker()->unique()->safeEmail(),
            'password' => password_hash(self::DEFAULT_PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]),
        ];
    }
}
