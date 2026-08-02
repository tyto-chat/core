<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\InvitableUsers;
use App\Dto\User\InvitableUserDto;
use App\Entity\MediaObject;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\MediaObject\SignedUrlServiceInterface;
use App\Service\User\UserServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @implements ProviderInterface<InvitableUsers>
 */
final readonly class InvitableUsersProvider implements ProviderInterface
{
    public function __construct(
        private UserServiceInterface $userService,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private SignedUrlServiceInterface $signedUrlService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvitableUsers
    {
        $request = $this->requestStack->getCurrentRequest();
        $search = $request?->query->get('search');
        $limit = (int) ($request?->query->get('limit', '20') ?? 20);

        $resource = new InvitableUsers();
        foreach ($this->userService->findInvitableForCurrentUser(\is_string($search) ? $search : null, $limit) as $item) {
            $resource->items[] = new InvitableUserDto($item['id'], $item['name'], $this->avatarUrl($item['avatar'] ?? null));
        }

        return $resource;
    }

    private function avatarUrl(?MediaObject $avatar): ?string
    {
        if (null === $avatar || null === $avatar->filePath || '' === $avatar->filePath) {
            return null;
        }

        $filter = MediaObjectServiceInterface::FILTERS['avatar']['md'];
        $relativePath = $filter.'/'.$avatar->filePath;

        return $this->urlGenerator->generate(
            'app_media_serve_variant',
            [
                'token' => $this->signedUrlService->sign($relativePath),
                'filter' => $filter,
                'filename' => $avatar->filePath,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
