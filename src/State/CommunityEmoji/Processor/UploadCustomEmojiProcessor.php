<?php

declare(strict_types=1);

namespace App\State\CommunityEmoji\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\CommunityEmoji;
use App\Entity\MediaObject;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<MediaObject, CommunityEmoji>
 */
final readonly class UploadCustomEmojiProcessor implements ProcessorInterface
{
    public function __construct(
        /** @var ProcessorInterface<MediaObject, MediaObject> */
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private CommunityServiceInterface $communityService,
        private CommunityEmojiServiceInterface $communityEmojiService,
        private MediaObjectServiceInterface $mediaObjectService,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommunityEmoji
    {
        $request = $this->requestStack->getCurrentRequest();
        $community = $this->communityService->getByIdentifier((string) $request?->attributes->get('community', ''));

        $shortcode = (string) $request?->request->get('shortcode', '');
        $rawName = trim((string) $request?->request->get('name', ''));
        $name = '' === $rawName ? null : $rawName;

        $this->communityEmojiService->assertValidCustomEmojiInput($shortcode, $name);

        $data->type = 'community_emoji';
        $this->mediaObjectService->populateFileMetadata($data);

        /** @var MediaObject $media */
        $media = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        $this->mediaObjectService->prepare($media, 'community_emoji');

        return $this->communityEmojiService->newCustom($community, $shortcode, $media, $name);
    }
}
