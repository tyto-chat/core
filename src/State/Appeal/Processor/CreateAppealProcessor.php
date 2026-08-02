<?php

declare(strict_types=1);

namespace App\State\Appeal\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Appeal\CreateAppealDto;
use App\Entity\Appeal;
use App\Service\Moderation\AppealServiceInterface;

/**
 * @implements ProcessorInterface<CreateAppealDto, Appeal>
 */
final readonly class CreateAppealProcessor implements ProcessorInterface
{
    public function __construct(
        private AppealServiceInterface $appealService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Appeal
    {
        /* @var CreateAppealDto $data */
        return $this->appealService->create((int) $uriVariables['actionId'], $data->reason);
    }
}
