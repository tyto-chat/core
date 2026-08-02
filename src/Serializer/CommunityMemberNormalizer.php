<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Service\UserGroup\UserGroupServiceInterface;
use App\Utils\ApiVersions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class CommunityMemberNormalizer implements NormalizerInterface, NormalizerAwareInterface, ResetInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'COMMUNITY_MEMBER_NORMALIZER_ALREADY_CALLED';

    /** @var array<int, array<int, UserGroup[]>> communityId → (userId → UserGroup[]) */
    private array $cache = [];

    /** @var array<int, int[]> communityId → hidden group IDs the viewer belongs to */
    private array $viewerHiddenGroupIds = [];

    public function __construct(
        private readonly Security $security,
        private readonly UserGroupServiceInterface $userGroupService,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        /** @var CommunityMember $object */
        // Transient admin member rows (never persisted) have no id — API Platform can't build an IRI, so supply a virtual one.
        if (null === $object->getId()) {
            $context['force_iri_generation'] = false;
            $context['iri'] = sprintf(
                '/api/%s/communities/%s/members/admin-%d',
                ApiVersions::CANONICAL,
                $object->getCommunity()->getIdentifier() ?? '',
                $object->getUserId() ?? 0,
            );
        }

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        $community = $object->getCommunity();
        $communityId = (int) $community->getId();

        $this->warmCache($community);

        $userId = $object->getUserId();
        $allGroups = null !== $userId ? ($this->cache[$communityId][$userId] ?? []) : [];

        $data['groups'] = array_values(array_map(
            fn (UserGroup $g) => [
                'id' => $g->getId(),
                'identifier' => $g->getIdentifier(),
                'name' => $g->getName(),
                'icon' => $g->getIcon(),
                'color' => $g->getColor(),
                'isHidden' => $g->getIsHidden(),
                'ownerId' => $g->getOwnerId(),
            ],
            array_filter($allGroups, fn (UserGroup $g) => $this->isGroupVisibleToViewer($g, $communityId))
        ));

        return $data;
    }

    private function warmCache(Community $community): void
    {
        $communityId = (int) $community->getId();

        if (isset($this->cache[$communityId])) {
            return;
        }

        $this->cache[$communityId] = $this->userGroupService->findGroupMembershipsIndexedByUser($community);

        $viewer = $this->security->getUser();
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');

        if ($isAdmin || !$viewer instanceof User) {
            $this->viewerHiddenGroupIds[$communityId] = null === $viewer ? [] : array_map(
                static fn (UserGroup $g) => (int) $g->getId(),
                array_filter(
                    array_merge(...array_values($this->cache[$communityId])),
                    static fn (UserGroup $g) => $g->getIsHidden()
                )
            );

            return;
        }

        $viewerId = (int) $viewer->getId();
        $viewerGroups = $this->cache[$communityId][$viewerId] ?? [];
        $this->viewerHiddenGroupIds[$communityId] = array_map(
            static fn (UserGroup $g) => (int) $g->getId(),
            array_filter($viewerGroups, static fn (UserGroup $g) => $g->getIsHidden())
        );
    }

    private function isGroupVisibleToViewer(UserGroup $group, int $communityId): bool
    {
        if (!$group->getIsHidden()) {
            return true;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return in_array((int) $group->getId(), $this->viewerHiddenGroupIds[$communityId] ?? [], true);
    }

    #[\Override]
    public function reset(): void
    {
        $this->cache = [];
        $this->viewerHiddenGroupIds = [];
    }

    /** @param array<string, mixed> $context */
    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof CommunityMember;
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [CommunityMember::class => false];
    }
}
