<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates an operator account interactively.
 *
 * The only way a user comes into existence: there is no registration endpoint by
 * decision. Prompts for username, e-mail and password (hidden, asked twice), hashes
 * with the configured `auto` hasher, and refuses a duplicate username or e-mail with
 * a non-zero exit code.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create an operator account',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $this->askNonEmpty($io, 'Username', function (string $value): void {
            if (\strlen($value) > 64) {
                throw new \RuntimeException('The username may be at most 64 characters.');
            }
        });

        $email = $this->askNonEmpty($io, 'E-mail address', function (string $value): void {
            if (false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('That is not a valid e-mail address.');
            }
        });

        if (null !== $this->users->findOneByUsername($username)) {
            $io->error(\sprintf('A user with the username "%s" already exists.', $username));

            return Command::FAILURE;
        }

        if (null !== $this->users->findOneBy(['email' => $email])) {
            $io->error(\sprintf('A user with the e-mail address "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Password', static function (?string $value): string {
            if (null === $value || '' === trim($value)) {
                throw new \RuntimeException('The password cannot be empty.');
            }

            return $value;
        });

        $repeated = (string) $io->askHidden('Repeat the password');

        if ($password !== $repeated) {
            $io->error('The passwords do not match.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Created operator "%s".', $username));

        return Command::SUCCESS;
    }

    /**
     * Ask a question until a non-empty answer passes the extra validation.
     */
    private function askNonEmpty(SymfonyStyle $io, string $question, ?callable $extra = null): string
    {
        return (string) $io->ask($question, null, static function (?string $value) use ($extra): string {
            $value = trim((string) $value);
            if ('' === $value) {
                throw new \RuntimeException('This value cannot be empty.');
            }

            if (null !== $extra) {
                $extra($value);
            }

            return $value;
        });
    }
}
