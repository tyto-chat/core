<?php

declare(strict_types=1);

namespace App\State\Appeal\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Appeal\UpdateAppealDto;
use App\Entity\Appeal;
use App\Service\Moderation\AppealServiceInterface;

/**
 * @implements ProcessorInterface<UpdateAppealDto, Appeal>
 */
final readonly class UpdateAppealProcessor implements ProcessorInterface
{
    public function __construct(
        private AppealServiceInterface $appealService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Appeal
    {
        /** @var UpdateAppealDto $data */
        $appeal = $this->appealService->get((int) $uriVariables['id']);

        return $this->appealService->resolve($appeal, $data);
    }
}
