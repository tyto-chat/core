<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Security\PermissionResolverInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ResetInterface;

// HTTP-cache bucket safety: never inject per-viewer data here (see CommunitySerializationCanaryTest).
final class CommunityNormalizer implements NormalizerInterface, NormalizerAwareInterface, ResetInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'COMMUNITY_NORMALIZER_ALREADY_CALLED';

    /** @var array<int, int>|null community id → member count, one grouped query per request */
    private ?array $memberCounts = null;

    public function __construct(
        private readonly Security $security,
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
        private readonly PermissionResolverInterface $permissions,
        private readonly bool $voiceEnabled,
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

        /** @var Community $object */
        $user = $this->security->getUser();
        assert($user instanceof User || null === $user);
        $isAdmin = $this->security->isGranted('ROLE_ADMIN')
            || ($user instanceof User && $this->permissions->isCommunityAdmin($user, $object));

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        $this->memberCounts ??= $this->communityMembershipService->countMembersPerCommunity();
        $data['memberCount'] = $this->memberCounts[(int) $object->getId()] ?? 0;

        if (isset($data['channels']) && is_array($data['channels'])) {
            /** @var list<array<string, mixed>> $channels */
            $channels = array_values($data['channels']);

            if (!$this->voiceEnabled) {
                $channels = array_values(array_filter(
                    $channels,
                    static fn (array $ch): bool => ($ch['type'] ?? null) !== ChannelType::Audio->value,
                ));
            }

            if (!$isAdmin) {
                $channels = $this->filterAccessibleChannels(
                    $channels,
                    $user instanceof User ? $user : null,
                    $object
                );
            }

            $data['channels'] = $channels;

            if (!$isAdmin && isset($data['channelSections']) && is_array($data['channelSections'])) {
                $visibleSectionIds = [];
                foreach ($channels as $ch) {
                    $sectionId = $ch['section']['id'] ?? null;
                    if (null !== $sectionId) {
                        $visibleSectionIds[$sectionId] = true;
                    }
                }
                /** @var list<array<string, mixed>> $sections */
                $sections = array_values($data['channelSections']);
                $data['channelSections'] = array_values(array_filter(
                    $sections,
                    static fn (array $s): bool => null !== ($s['id'] ?? null) && isset($visibleSectionIds[$s['id']]),
                ));
            }
        }

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $serializedChannels Normalized channel data (has 'id' and 'isPrivate')
     *
     * @return list<array<string, mixed>>
     */
    private function filterAccessibleChannels(array $serializedChannels, ?User $user, Community $community): array
    {
        $grantedChannelIds = [];
        if (null !== $user && $this->permissions->isMember($user, $community)) {
            $grantedChannelIds = $this->permissions->directChannelRoles($user, $community)
                + $this->permissions->groupChannelRoles($user, $community);
        }

        return array_values(array_filter(
            $serializedChannels,
            static function (array $ch) use ($grantedChannelIds): bool {
                if (empty($ch['isPrivate'])) {
                    return true;
                }
                $channelId = $ch['id'] ?? null;

                return null !== $channelId && isset($grantedChannelIds[$channelId]);
            }
        ));
    }

    #[\Override]
    public function reset(): void
    {
        $this->memberCounts = null;
    }

    /** @param array<string, mixed> $context */
    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof Community;
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [Community::class => false];
    }
}
