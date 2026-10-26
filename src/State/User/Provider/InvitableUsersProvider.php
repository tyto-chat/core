<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\InvitableUsers;
use App\Dto\User\InvitableUserDto;
use App\Service\MediaObject\AvatarUrlResolverInterface;
use App\Service\User\UserServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<InvitableUsers>
 */
final readonly class InvitableUsersProvider implements ProviderInterface
{
    public function __construct(
        private UserServiceInterface $userService,
        private RequestStack $requestStack,
        private AvatarUrlResolverInterface $avatarUrls,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvitableUsers
    {
        $request = $this->requestStack->getCurrentRequest();
        $search = $request?->query->get('search');
        $limit = (int) ($request?->query->get('limit', '20') ?? 20);

        $resource = new InvitableUsers();
        foreach ($this->userService->findInvitableForCurrentUser(\is_string($search) ? $search : null, $limit) as $item) {
            $resource->items[] = new InvitableUserDto($item['id'], $item['name'], $this->avatarUrls->avatarUrl($item['avatar'] ?? null));
        }

        return $resource;
    }
}
