<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\Admin\ServerConfigPatchDto;
use App\Dto\User\CreateUserDto;
use App\Enum\User\UserRole;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Settings\Settings;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:user:create',
    description: 'Create a user.',
)]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserServiceInterface $userService,
        private SettingsServiceInterface $settings,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        InputInterface $input,
        #[Option]
        bool $admin = false,
        #[Option]
        bool $generatePassword = false,
        #[Option]
        bool $bot = false,
        #[Option]
        bool $skipIfDefaultExists = false,
        #[Argument]
        string $displayName = 'Tyto Bot',
    ): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('User creator');

        if ($bot) {
            return $this->createBotUser($io, $displayName, $skipIfDefaultExists);
        }

        if ($admin) {
            $io->info('Creating admin user');
        }
        $email = $io->ask('Provide email address for the new user', null, function (?string $email): string {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email address.');
            }

            return $email;
        });

        $displayName = $io->ask('Provide display name (how others will see you)', null, function (?string $name): string {
            if (strlen((string) $name) < 4) {
                throw new \InvalidArgumentException('Display name must be at least 4 characters.');
            }

            return (string) $name;
        });

        $password = substr(bin2hex(random_bytes(16)), 0, 16);
        if (!$generatePassword) {
            while (true) {
                try {
                    $password = $this->getUserPassword($io);

                    break;
                } catch (\InvalidArgumentException $e) {
                    $io->error($e->getMessage());
                }
            }
        }

        $userDto = new CreateUserDto($email, $password, $displayName);
        try {
            $user = $this->userService->new($userDto, $admin ? [UserRole::Admin->value] : []);
        } catch (UniqueConstraintViolationException) {
            $io->error('A user with this email already exists.');

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Email', 'Password'], [[$user->getEmail(), false === $generatePassword ? '<hidden>' : $password]]);
        $io->success('User created successfully.');

        return Command::SUCCESS;
    }

    private function createBotUser(SymfonyStyle $io, string $displayName, bool $skipIfDefaultExists): int
    {
        $defaultBotId = $this->settings->get(Settings::defaultBotId());
        if ($skipIfDefaultExists && 0 !== $defaultBotId) {
            $io->info(sprintf('Default bot already configured (defaultBotId=%d) — skipping.', $defaultBotId));

            return Command::SUCCESS;
        }

        $io->info(sprintf('Creating bot user "%s"', $displayName));

        try {
            $user = $this->userService->newBot($displayName);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['ID', 'Display Name', 'Email'], [[$user->getId(), $displayName, $user->getEmail()]]);

        if (0 === $defaultBotId) {
            $dto = new ServerConfigPatchDto();
            $dto->defaultBotId = (int) $user->getId();
            $this->settings->applyPatch($dto);
            $io->success(sprintf('Bot user created (ID: %1$d) and set as the default bot (defaultBotId=%1$d).', $user->getId()));
        } else {
            $io->success(sprintf('Bot user created (ID: %d). Assign it a role in the admin settings panel.', $user->getId()));
        }

        return Command::SUCCESS;
    }

    private function getUserPassword(SymfonyStyle $io): string
    {
        $password = $io->askHidden('Provide password for the new user', function (?string $password): string {
            if (null === $password || strlen($password) < 8) {
                throw new \InvalidArgumentException('Password must be minium 8 characters long.');
            }

            return $password;
        });

        $repeatedPassword = $io->askHidden('Repeat password', function (?string $password): string {
            if (null === $password || strlen($password) < 8) {
                throw new \InvalidArgumentException('Password must be minium 8 characters long.');
            }

            return $password;
        });

        if ($password !== $repeatedPassword) {
            throw new \InvalidArgumentException('Passwords do not match');
        }

        return $password;
    }
}
