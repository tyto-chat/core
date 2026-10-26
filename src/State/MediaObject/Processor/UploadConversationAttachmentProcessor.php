<?php

declare(strict_types=1);

namespace App\State\MediaObject\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<MediaObject, MediaObject>
 */
final readonly class UploadConversationAttachmentProcessor implements ProcessorInterface
{
    public function __construct(
        /** @var ProcessorInterface<MediaObject, MediaObject> */
        private ProcessorInterface $processor,
        private readonly ConversationServiceInterface $conversationService,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MediaObject
    {
        /** @var MediaObject $data */
        $request = $this->requestStack->getCurrentRequest();
        $identifier = (string) $request?->attributes->get('conversation', '');
        $conversation = $this->conversationService->getByIdentifier($identifier);

        $this->conversationService->assertCanUpload($conversation);

        $data->type = 'attachment';
        $this->mediaObjectService->populateFileMetadata($data);

        /** @var MediaObject $media */
        $media = $this->processor->process($data, $operation, $uriVariables, $context);
        $this->mediaObjectService->prepare($media, 'attachment');

        return $media;
    }
}
