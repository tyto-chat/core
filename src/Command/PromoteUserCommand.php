<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\User\UserRole;
use App\Service\User\UserServiceInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:user:promote',
    description: 'Promote a user to administrator (ROLE_ADMIN) by email.',
)]
final readonly class PromoteUserCommand
{
    public function __construct(private UserServiceInterface $userService)
    {
    }

    public function __invoke(
        OutputInterface $output,
        InputInterface $input,
        #[Argument(description: 'Email address of the user to promote')]
        string $email,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('User promoter');

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address.');

            return Command::INVALID;
        }

        $user = $this->userService->findByEmail($email);
        if (null === $user) {
            $io->error(sprintf('No user found with email "%s".', $email));

            return Command::FAILURE;
        }

        if (in_array(UserRole::Admin->value, $user->getRoles(), true)) {
            $io->info(sprintf('User "%s" is already an administrator.', $email));

            return Command::SUCCESS;
        }

        $this->userService->grantAdminRole($user);

        $io->success(sprintf('User "%s" promoted to administrator.', $email));

        return Command::SUCCESS;
    }
}
