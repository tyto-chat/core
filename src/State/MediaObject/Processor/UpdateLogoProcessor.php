<?php

declare(strict_types=1);

namespace App\State\MediaObject\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Service\Community\CommunityServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<MediaObject, MediaObject>
 */
final readonly class UpdateLogoProcessor implements ProcessorInterface
{
    public function __construct(
        /** @var ProcessorInterface<MediaObject, MediaObject> */
        private readonly ProcessorInterface $processor,
        private readonly RequestStack $requestStack,
        private readonly CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MediaObject
    {
        $request = $this->requestStack->getCurrentRequest();
        $communityIdentifier = $request->attributes->get('identifier');
        $community = $this->communityService->getByIdentifier($communityIdentifier);

        $logo = $this->processor->process($data, $operation, $uriVariables, $context);
        $this->communityService->setLogo($community, $logo);

        return $logo;
    }
}
