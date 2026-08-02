<?php

declare(strict_types=1);

namespace App\State\Community\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Community\MyCommunityMembershipDto;
use App\Entity\User;
use App\Repository\CommunityMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MyCommunityMembershipDto>
 */
final readonly class MyCommunityMembershipsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private CommunityMemberRepository $communityMemberRepository,
    ) {
    }

    /** @return list<MyCommunityMembershipDto> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): MyCommunityMembershipDto => new MyCommunityMembershipDto(
                $row['communityId'],
                $row['communityIdentifier'],
                $row['role']->value,
            ),
            $this->communityMemberRepository->findMembershipsForUser($user),
        ));
    }
}
