<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Search\SearchReindexServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:search:reindex',
    description: 'Push all messages into the search index. Idempotent — safe to re-run.',
)]
final class ReindexSearchCommand extends Command
{
    public function __construct(private readonly SearchReindexServiceInterface $searchReindexService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $total = $this->searchReindexService->countIndexable();
        $io->writeln(sprintf('Indexing %d active messages…', $total));
        $io->progressStart($total);

        $indexed = $this->searchReindexService->reindex(
            static fn (int $batchSize) => $io->progressAdvance($batchSize),
        );

        $io->progressFinish();
        $io->success(sprintf('Indexed %d messages.', $indexed));

        return Command::SUCCESS;
    }
}
