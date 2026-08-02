<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityEmoji;
use App\Entity\MediaObject;
use App\Exception\CommunityEmoji\EmojiNameTooLongException;
use App\Exception\CommunityEmoji\InvalidShortcodeException;
use App\Exception\CommunityEmoji\ShortcodeAlreadyTakenException;
use App\Repository\CommunityEmojiRepository;
use App\Security\SecurityContext;
use App\Security\Voter\CommunityEmojiVoter;
use App\Service\AbstractDoctrineService;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Reaction\ReactionServiceInterface;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

class CommunityEmojiService extends AbstractDoctrineService implements CommunityEmojiServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityEmojiRepository $communityEmojiRepository,
        #[Lazy]
        private readonly ReactionServiceInterface $reactionService,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly StructureRealtimePublisherInterface $publisher,
        // CachePurgingStructurePublisher does not purge emoji lists — purge directly here.
        private readonly CachePurgerInterface $cachePurger,
    ) {
    }

    /** @return CommunityEmoji[] */
    #[\Override]
    public function getAllForCommunity(Community $community): array
    {
        $this->security->throwAccessDeniedUnlessGranted(
            CommunityEmojiVoter::VIEW,
            $community,
            'You do not have permission to view emojis for this community.',
        );

        return $this->communityEmojiRepository->findByCommunity($community);
    }

    #[\Override]
    public function findByShortcode(Community $community, string $shortcode): ?CommunityEmoji
    {
        return $this->communityEmojiRepository->findByCommunityAndShortcode($community, $shortcode);
    }

    #[\Override]
    public function findByImage(MediaObject $image): ?CommunityEmoji
    {
        return $this->communityEmojiRepository->findOneBy(['image' => $image]);
    }

    #[\Override]
    public function assertValidCustomEmojiInput(string $shortcode, ?string $name): void
    {
        if (1 !== preg_match(CommunityEmojiServiceInterface::SHORTCODE_PATTERN, $shortcode)) {
            throw new InvalidShortcodeException('Shortcode must match :[a-z0-9_-]{2,32}: format.');
        }
        if (null !== $name && mb_strlen($name) > 64) {
            throw new EmojiNameTooLongException('Emoji name must be 64 characters or fewer.');
        }
    }

    #[\Override]
    public function newCustom(Community $community, string $shortcode, MediaObject $image, ?string $name = null): CommunityEmoji
    {
        $this->security->throwAccessDeniedUnlessGranted(
            CommunityEmojiVoter::MANAGE,
            $community,
            'You do not have permission to manage emojis for this community.',
        );

        $this->assertValidCustomEmojiInput($shortcode, $name);
        $this->assertShortcodeAvailable($community, $shortcode);

        $emoji = new CommunityEmoji();
        $emoji->setCommunity($community);
        $emoji->setShortcode($shortcode);
        $emoji->setName($name);
        $emoji->setImage($image);
        $emoji->setPosition($this->nextPosition($community));

        $saved = $this->save($emoji);
        $this->publisher->publishCommunityEmojisUpdated($community);
        $this->cachePurger->purgeCommunityEmojis((string) $community->getIdentifier());

        return $saved;
    }

    #[\Override]
    public function delete(CommunityEmoji $emoji): void
    {
        $community = $emoji->getCommunity();
        $this->security->throwAccessDeniedUnlessGranted(
            CommunityEmojiVoter::MANAGE,
            $community,
            'You do not have permission to manage emojis for this community.',
        );

        if (null !== $community) {
            $this->reactionService->removeCommunityReactionsForEmojis($community, [$emoji->getShortcode()]);
        }

        $image = $emoji->getImage();
        $this->removeAndFlush($emoji);
        if (null !== $image) {
            $this->mediaObjectService->delete($image);
        }

        if (null !== $community) {
            $this->publisher->publishCommunityEmojisUpdated($community);
            $this->cachePurger->purgeCommunityEmojis((string) $community->getIdentifier());
        }
    }

    private function assertShortcodeAvailable(Community $community, string $shortcode): void
    {
        if (null !== $this->communityEmojiRepository->findByCommunityAndShortcode($community, $shortcode)) {
            throw new ShortcodeAlreadyTakenException(sprintf('Shortcode "%s" is already in use for this community.', $shortcode));
        }
    }

    private function nextPosition(Community $community): int
    {
        return ($this->communityEmojiRepository->findMaxPosition($community) ?? -1) + 1;
    }
}
