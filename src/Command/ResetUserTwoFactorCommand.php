<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Admin\AdminAuditAction;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\User\TwoFactorServiceInterface;
use App\Service\User\UserServiceInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:user:reset-2fa',
    description: 'Reset a user\'s two-factor authentication so they can sign in with just their password.',
)]
final readonly class ResetUserTwoFactorCommand
{
    public function __construct(
        private UserServiceInterface $userService,
        private TwoFactorServiceInterface $twoFactorService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        InputInterface $input,
        #[Argument]
        string $email,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $user = $this->userService->findByEmail($email);
        if (null === $user) {
            $io->error(sprintf('No user found for email "%s".', $email));

            return Command::FAILURE;
        }

        if (!$user->isTwoFactorEnabled() && null === $user->getTwoFactorSecret()) {
            $io->info(sprintf('Two-factor authentication is not enabled for "%s". Nothing to do.', $email));

            return Command::SUCCESS;
        }

        $this->twoFactorService->reset($user);
        $this->auditLogger->record(AdminAuditAction::UserTwoFactorDisable, 'user', $user->getId());

        $io->success(sprintf('Two-factor authentication reset for "%s". They can now sign in with just their password.', $email));

        return Command::SUCCESS;
    }
}
