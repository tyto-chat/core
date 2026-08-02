<?php

declare(strict_types=1);

namespace App\State\Presence\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\Presence\PresenceServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MarkOfflineProcessor implements ProcessorInterface
{
    public function __construct(
        private PresenceServiceInterface $presenceService,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var User $user */
        $user = $this->security->getUser();
        $this->presenceService->offline($user);

        return null;
    }
}
