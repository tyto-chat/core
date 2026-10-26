<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MediaObject\MediaObjectServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tyto:media:generate-thumbnails',
    description: 'Generate missing thumbnails for existing image attachments. Idempotent — safe to re-run.',
)]
final class GenerateAttachmentThumbnailsCommand extends Command
{
    private ?int $currentId = null;

    public function __construct(private readonly MediaObjectServiceInterface $mediaObjectService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from-id', null, InputOption::VALUE_REQUIRED, 'Only process attachments with an id greater than this one.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fromId = (int) $input->getOption('from-id');

        register_shutdown_function(function () use ($output): void {
            if (null !== $this->currentId) {
                $output->writeln(sprintf('<error>Stopped while processing attachment %1$d. Re-run with --from-id=%1$d to skip it.</error>', $this->currentId));
            }
        });

        $generated = $this->mediaObjectService->generateMissingAttachmentThumbnails(
            function (int $attachmentId) use ($io): void {
                $this->currentId = $attachmentId;
                $io->isVerbose() ? $io->writeln(sprintf('attachment %d', $attachmentId)) : $io->write('.');
            },
            $fromId,
        );
        $this->currentId = null;

        $io->newLine();
        $io->success(sprintf('Generated %d thumbnails.', $generated));

        return Command::SUCCESS;
    }
}
