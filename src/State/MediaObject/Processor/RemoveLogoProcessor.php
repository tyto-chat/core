<?php

declare(strict_types=1);

namespace App\State\MediaObject\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Service\Community\CommunityServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<MediaObject, void>
 */
final readonly class RemoveLogoProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $identifier = (string) $this->requestStack->getCurrentRequest()?->attributes->get('identifier');
        $community = $this->communityService->getByIdentifier($identifier);

        if (null === $community->getLogo()) {
            throw new NotFoundHttpException('This community has no logo.');
        }

        $this->communityService->removeLogo($community);
    }
}
