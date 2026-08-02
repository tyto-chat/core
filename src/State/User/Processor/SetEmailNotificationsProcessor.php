<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\SetEmailNotificationsDto;
use App\Entity\User;
use App\Service\User\UserServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<SetEmailNotificationsDto, User>
 */
final readonly class SetEmailNotificationsProcessor implements ProcessorInterface
{
    public function __construct(
        private UserServiceInterface $userService,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        \assert(null !== $data->enabled);
        $locale = $this->requestStack->getCurrentRequest()?->getLocale();

        return $this->userService->setEmailNotifications($data->enabled, $locale);
    }
}
