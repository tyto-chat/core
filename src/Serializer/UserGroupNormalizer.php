<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\UserGroup;
use App\Service\UserGroup\UserGroupServiceInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class UserGroupNormalizer implements NormalizerInterface, NormalizerAwareInterface, ResetInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'USER_GROUP_NORMALIZER_ALREADY_CALLED';

    /** @var array<int, array<int, int>> community id → (group id → member count) */
    private array $counts = [];

    public function __construct(
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

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        $data['memberCount'] = $this->memberCountFor($object);

        return $data;
    }

    private function memberCountFor(UserGroup $group): int
    {
        $groupId = $group->getId();
        if (null === $groupId) {
            return $this->userGroupService->countMembers($group);
        }

        $community = $group->getCommunity();
        $communityId = $community->getId();
        $this->counts[$communityId] ??= $this->userGroupService->countMembersPerGroupInCommunity($community);

        return $this->counts[$communityId][$groupId] ?? 0;
    }

    #[\Override]
    public function reset(): void
    {
        $this->counts = [];
    }

    /** @param array<string, mixed> $context */
    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof UserGroup;
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [UserGroup::class => false];
    }
}
