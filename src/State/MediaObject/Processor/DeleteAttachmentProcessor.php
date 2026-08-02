<?php

declare(strict_types=1);

namespace App\State\MediaObject\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Security\ApiKeyScopeGuard;
use App\Service\MediaObject\MediaObjectServiceInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<MediaObject, void>
 */
final readonly class DeleteAttachmentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        /** @var MediaObject $data */
        if ('attachment' !== $data->type) {
            throw new BadRequestHttpException('Only attachment media objects can be deleted via this endpoint.');
        }

        if (null !== $data->message?->getConversation()) {
            $this->scopeGuard->requireScope('conversations:write');
        }

        $this->mediaObjectService->deleteAttachment($data);
    }
}
