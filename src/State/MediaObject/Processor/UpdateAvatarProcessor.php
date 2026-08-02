<?php

declare(strict_types=1);

namespace App\State\MediaObject\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<MediaObject, MediaObject>
 */
final readonly class UpdateAvatarProcessor implements ProcessorInterface
{
    public function __construct(
        /** @var ProcessorInterface<MediaObject, MediaObject> */
        private ProcessorInterface $processor,
        private readonly UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MediaObject
    {
        $avatar = $this->processor->process($data, $operation, $uriVariables, $context);
        $this->userService->setAvatar($avatar);

        return $avatar;
    }
}
