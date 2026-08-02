<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\User\UserRole;
use App\Repository\CommunityRepository;
use App\Repository\MessageRepository;
use App\Security\PermissionResolverInterface;

final class CacheContextService implements CacheContextServiceInterface
{
    private const string ANON = 'v1:anon';

    public function __construct(
        private readonly CommunityRepository $communities,
        private readonly PermissionResolverInterface $permissions,
        private readonly MessageRepository $messages,
    ) {
    }

    #[\Override]
    public function bucketFor(?User $user, string $uri): string
    {
        if (null === $user) {
            return self::ANON;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return self::ANON;
        }

        // Caddy matches the percent-decoded path — decode here too or encoded URLs cache in Caddy but bucket anon.
        $path = rawurldecode($path);

        // Must stay in lockstep with the @cacheable path_regexp in docker/frankenphp/Caddyfile — a shape cached there but unrecognized here buckets anon.
        // DM messages bucket per-user (fingerprintForConversation) — a shared bucket would serve one participant's cached DM to any authenticated user.
        if (1 === preg_match('#^/api/v\d+/messages/([0-9a-f\-]{36})(?:/thread)?$#', $path, $m)) {
            return $this->bucketForMessage($user, $m[1]);
        }

        // Suffix must stay anchored after the identifier — a greedy alternative would also match non-cacheable subresources like /membership or /invites.
        if (1 !== preg_match('#^/api/v\d+/communities/([^/]+)(?:/(?:channels/[^/]+/(?:pages/\d+|messages/current|pinned-messages)|emojis|presence/summary))?$#', $path, $m)) {
            return self::ANON;
        }

        $community = $this->communities->findOneBy(['identifier' => $m[1]]);
        if (null === $community) {
            return self::ANON;
        }

        return $this->bucketForCommunity($user, $community);
    }

    private function bucketForMessage(User $user, string $uuid): string
    {
        $message = $this->messages->findOneBy(['id' => $uuid]);
        if (null === $message) {
            return self::ANON;
        }

        $community = $message->getChannel()?->getCommunity();
        if (null !== $community) {
            return $this->bucketForCommunity($user, $community);
        }

        $conversation = $message->getConversation();
        if (null !== $conversation) {
            return $this->fingerprintForConversation($user, $conversation);
        }

        return self::ANON;
    }

    private function fingerprintForConversation(User $user, Conversation $conversation): string
    {
        $material = implode('|', [
            'v1',
            'u'.($user->getId() ?? 0),
            'D'.($conversation->getId() ?? 0),
        ]);

        return 'v1:'.hash('sha256', $material);
    }

    private function bucketForCommunity(User $user, Community $community): string
    {
        $globalAdmin = in_array(UserRole::Admin->value, $user->getRoles(), true);
        $communityAdmin = $this->permissions->isCommunityAdmin($user, $community);
        $communityMod = $this->permissions->isCommunityModerator($user, $community);
        $channelRoles = $this->permissions->directChannelRoles($user, $community);
        $groupRoles = $this->permissions->groupChannelRoles($user, $community);

        if ($globalAdmin || $communityAdmin || $communityMod || [] !== $channelRoles || [] !== $groupRoles) {
            return $this->fingerprint($user, $community->getId(), $globalAdmin, $communityAdmin, $communityMod, $channelRoles, $groupRoles);
        }

        if ($community->isPrivate() && !$this->permissions->isMember($user, $community)) {
            return self::ANON;
        }

        return sprintf('v1:C%d:standard', $community->getId());
    }

    /**
     * @param array<int, ChannelRole> $channelRoles
     * @param array<int, ChannelRole> $groupRoles
     */
    private function fingerprint(User $user, int $communityId, bool $globalAdmin, bool $communityAdmin, bool $communityMod, array $channelRoles, array $groupRoles): string
    {
        $serialize = static function (array $roles): string {
            ksort($roles);
            $parts = [];
            foreach ($roles as $channelId => $role) {
                $parts[] = $channelId.':'.$role->value;
            }

            return implode(',', $parts);
        };

        $material = implode('|', [
            'v1',
            'u'.($user->getId() ?? 0),
            'C'.$communityId,
            $globalAdmin ? 'ga' : '-',
            $communityAdmin ? 'ca' : '-',
            $communityMod ? 'cm' : '-',
            $serialize($channelRoles),
            $serialize($groupRoles),
        ]);

        return 'v1:'.hash('sha256', $material);
    }
}
