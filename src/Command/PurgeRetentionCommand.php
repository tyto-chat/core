<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Retention\RetentionServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:retention:purge',
    description: 'Apply data-retention settings: redact old message content, delete old attachments and notifications. Idempotent — run from cron.',
)]
final class PurgeRetentionCommand extends Command
{
    public function __construct(
        private readonly RetentionServiceInterface $retentionService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->retentionService->purge();

        $io->success(sprintf(
            'Retention purge complete: %d messages redacted, %d messages stripped of attachments, %d notifications deleted.',
            $result['messages'],
            $result['attachments'],
            $result['notifications'],
        ));

        return Command::SUCCESS;
    }
}
