<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\HttpCache\HttpCacheFlusherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:http-cache:purge-all',
    description: 'Delete every response stored in the HTTP edge cache. Safe to run at any time; the cache refills on demand.',
)]
final class PurgeAllHttpCacheCommand extends Command
{
    public function __construct(private readonly HttpCacheFlusherInterface $flusher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln(sprintf('HTTP cache: purged %d keys', $this->flusher->flushAll()));

        return Command::SUCCESS;
    }
}
