<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\HttpCache\HttpCacheSwitchInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:http-cache:sync',
    description: 'Mirror the HTTP cache TTL settings into the flag file the edge cache reads. Idempotent.',
)]
final class SyncHttpCacheCommand extends Command
{
    public function __construct(private readonly HttpCacheSwitchInterface $httpCacheSwitch)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln($this->httpCacheSwitch->sync() ? 'HTTP cache: on' : 'HTTP cache: off');

        return Command::SUCCESS;
    }
}
