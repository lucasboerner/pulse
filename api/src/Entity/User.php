<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An operator account, created only by the app:user:create console command.
 *
 * The username is the login identifier; the email is where this user's alert mails
 * go and is never a login field. There is deliberately no registration, no password
 * reset and no role column: every account can do everything, so getRoles() is a
 * constant.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use EntityIdTrait;
    use TimestampableEntity;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $username;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $password;

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        // The username is a non-null, unique login identifier; the assertion
        // satisfies UserInterface's non-empty-string contract and is compiled
        // out in production.
        \assert('' !== $this->username);

        return $this->username;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
        // No transient sensitive data is held on the entity.
    }
}
