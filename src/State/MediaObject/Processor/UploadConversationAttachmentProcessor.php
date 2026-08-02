<?php

declare(strict_types=1);

namespace App\State\MediaObject\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Exception\AccessDeniedException;
use App\Security\Voter\ConversationVoter;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
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
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MediaObject
    {
        /** @var MediaObject $data */
        $request = $this->requestStack->getCurrentRequest();
        $identifier = (string) $request?->attributes->get('conversation', '');
        $conversation = $this->conversationService->getByIdentifier($identifier);

        if (!$this->security->isGranted(ConversationVoter::WRITE, $conversation)) {
            throw new AccessDeniedException('You must be an active member of this conversation to upload attachments.');
        }

        $data->type = 'attachment';
        $this->mediaObjectService->populateFileMetadata($data);

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}
