<?php

declare(strict_types=1);

namespace App\State\MediaObject\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProcessorInterface<MediaObject, MediaObject>
 */
final readonly class UploadAttachmentProcessor implements ProcessorInterface
{
    public function __construct(
        /** @var ProcessorInterface<MediaObject, MediaObject> */
        private ProcessorInterface $processor,
        private readonly CommunityServiceInterface $communityService,
        private readonly ChannelServiceInterface $channelService,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MediaObject
    {
        /** @var MediaObject $data */
        $request = $this->requestStack->getCurrentRequest();
        $community = $this->communityService->getByIdentifier((string) $request?->attributes->get('community', ''));
        $channel = $this->channelService->getByIdentifier((string) $request?->attributes->get('channel', ''), $community);

        if (!$channel->getAllowAttachments()) {
            throw new AccessDeniedHttpException('Attachments are disabled for this channel.');
        }

        $data->type = 'attachment';
        $this->mediaObjectService->populateFileMetadata($data);

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}
