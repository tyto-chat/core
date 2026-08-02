<?php

declare(strict_types=1);

namespace App\State\Challenge\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Challenge\CreateChallengeDto as ChallengeDto;
use App\Exception\User\EmailAlreadyTakenException;
use App\Service\Challenge\ChallengeServiceInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * @implements ProcessorInterface<ChallengeDto, void>
 */
final class CreateChallengeProcessor implements ProcessorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private ChallengeServiceInterface $challengeService,
    ) {
    }

    #[\Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        try {
            $this->challengeService->new($data);
            $this->logger->info(sprintf('A new registration challenge created for email "%s".', $data->email));
        } catch (EmailAlreadyTakenException $exception) {
            $this->logger->notice($exception->getMessage());
        }
    }
}
