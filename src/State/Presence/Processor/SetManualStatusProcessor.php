<?php

declare(strict_types=1);

namespace App\State\Presence\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Presence\SetManualStatusDto;
use App\Entity\User;
use App\Service\Presence\PresenceServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<SetManualStatusDto, SetManualStatusDto>
 */
final readonly class SetManualStatusProcessor implements ProcessorInterface
{
    public function __construct(
        private PresenceServiceInterface $presenceService,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SetManualStatusDto
    {
        /** @var SetManualStatusDto $data */
        /** @var User $user */
        $user = $this->security->getUser();

        $this->presenceService->setManualStatus($user, $data->status);

        return new SetManualStatusDto(
            status: $data->status,
            state: $this->presenceService->get($user)->state,
        );
    }
}
