<?php

declare(strict_types=1);

namespace App\State\MediaObject\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\MediaObject;
use App\Service\Community\CommunityServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<MediaObject>
 */
final readonly class CommunityLogoProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MediaObject
    {
        $identifier = (string) $this->requestStack->getCurrentRequest()?->attributes->get('identifier');
        $community = $this->communityService->getByIdentifier($identifier);

        $logo = $community->getLogo();
        if (null === $logo) {
            throw new NotFoundHttpException('This community has no logo.');
        }

        return $logo;
    }
}
