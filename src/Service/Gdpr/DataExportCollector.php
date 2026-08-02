<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Entity\ConversationMember;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\MessageRevision;
use App\Entity\ModerationAction;
use App\Entity\Notification;
use App\Entity\Reaction;
use App\Entity\User;
use App\Service\MediaObject\SignedUrlServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal read-only EM access — sanctioned reporting exemption
 */
class DataExportCollector
{
    /** Signed URLs are bearer tokens inside a redistributable ZIP — keep this TTL short. */
    public const int ATTACHMENT_TTL_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SignedUrlServiceInterface $signedUrlService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function attachmentTtlSeconds(): int
    {
        return self::ATTACHMENT_TTL_HOURS * 3600;
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getProfile()?->getName(),
            'locale' => $user->getLocale(),
            'roles' => $user->getRoles(),
            'emailNotifications' => $user->isEmailNotifications(),
            'isBot' => $user->isBot(),
            'createdAt' => self::formatDateTimeOrNull($user->getCreatedAt()),
            'updatedAt' => self::formatDateTimeOrNull($user->getUpdatedAt()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages(User $user, int $signedUrlTtlSeconds): array
    {
        /** @var Message[] $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.createdBy = :u')
            ->setParameter('u', $user)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $msg) {
            $bodies = [];
            foreach ($msg->getRevisions() as $idx => $revision) {
                \assert($revision instanceof MessageRevision);
                $bodies[] = [
                    'body' => $revision->getText(),
                    'createdAt' => self::formatDateTimeOrNull($revision->getCreatedAt()),
                    'isOriginal' => 0 === $idx,
                ];
            }

            $attachments = [];
            foreach ($msg->getAttachments() as $mo) {
                \assert($mo instanceof MediaObject);
                $attachments[] = [
                    'id' => $mo->getId(),
                    'originalName' => $mo->originalName,
                    'mimeType' => $mo->mimeType,
                    'size' => $mo->size,
                    'signedUrl' => $this->buildAttachmentSignedUrl($mo, $signedUrlTtlSeconds),
                ];
            }

            $page = $msg->getPage();
            $channel = $page?->getChannel();
            $conversation = $page?->getConversation();
            $container = null !== $channel ? 'channel' : (null !== $conversation ? 'conversation' : null);

            $out[] = [
                'id' => $msg->getId(),
                'kind' => $msg->getKind()->value,
                'container' => $container,
                'channelIdentifier' => $channel?->getIdentifier(),
                'communityIdentifier' => $channel?->getCommunity()?->getIdentifier(),
                'conversationIdentifier' => $conversation?->getIdentifier(),
                'parentId' => $msg->getParent()?->getId(),
                'createdAt' => self::formatDateTimeOrNull($msg->getCreatedAt()),
                'updatedAt' => self::formatDateTimeOrNull($msg->getUpdatedAt()),
                'isDeleted' => $msg->isDeleted(),
                'deletedAt' => self::formatDateTimeOrNull($msg->getDeletedAt()),
                'edited' => $msg->isEdited(),
                'history' => $bodies,
                'attachments' => $attachments,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conversations(User $user): array
    {
        /** @var ConversationMember[] $memberships */
        $memberships = $this->em->createQueryBuilder()
            ->select('cm', 'c')
            ->from(ConversationMember::class, 'cm')
            ->join('cm.conversation', 'c')
            ->where('cm.user = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($memberships as $cm) {
            $convo = $cm->getConversation();
            $others = [];
            foreach ($convo->getMembers() as $other) {
                if ($other->getUserId() === $user->getId()) {
                    continue;
                }
                $others[] = $other->getProfile()?->getName() ?? '(unknown user)';
            }

            $out[] = [
                'conversationIdentifier' => $convo->getIdentifier(),
                'joinedAt' => self::formatDateTimeOrNull($cm->getJoinedAt()),
                'lastReadAt' => self::formatDateTimeOrNull($cm->getLastReadAt()),
                'mutedUntil' => self::formatDateTimeOrNull($cm->getMutedUntil()),
                'otherParticipantNames' => $others,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attachments(User $user, int $signedUrlTtlSeconds): array
    {
        /** @var MediaObject[] $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('mo')
            ->from(MediaObject::class, 'mo')
            ->where('mo.createdBy = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $mo) {
            if (null === $mo->filePath || 'attachment' !== $mo->type) {
                continue;
            }

            $out[] = [
                'id' => $mo->getId(),
                'originalName' => $mo->originalName,
                'mimeType' => $mo->mimeType,
                'size' => $mo->size,
                'width' => $mo->width,
                'height' => $mo->height,
                'createdAt' => self::formatDateTimeOrNull($mo->getCreatedAt()),
                'signedUrl' => $this->buildAttachmentSignedUrl($mo, $signedUrlTtlSeconds),
            ];
        }

        return $out;
    }

    private function buildAttachmentSignedUrl(MediaObject $mo, int $ttlSeconds): ?string
    {
        if (null === $mo->filePath) {
            return null;
        }

        return $this->urlGenerator->generate(
            'app_media_serve_export',
            [
                'token' => $this->signedUrlService->sign($mo->filePath, $ttlSeconds),
                'filename' => $mo->filePath,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reactions(User $user): array
    {
        /** @var Reaction[] $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('r')
            ->from(Reaction::class, 'r')
            ->where('r.createdBy = :u')
            ->setParameter('u', $user)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'emoji' => $r->getEmoji(),
                'messageId' => $r->getMessage()?->getId(),
                'createdAt' => self::formatDateTimeOrNull($r->getCreatedAt()),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function moderationAsTarget(User $user): array
    {
        /** @var ModerationAction[] $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('a')
            ->from(ModerationAction::class, 'a')
            ->where('a.targetUser = :u')
            ->setParameter('u', $user)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $a) {
            $out[] = [
                'type' => $a->getType()->value,
                'reason' => $a->getReason(),
                'communityIdentifier' => $a->getCommunityIdentifier(),
                'channelIdentifier' => $a->getChannelIdentifier(),
                'createdAt' => self::formatDateTimeOrNull($a->getCreatedAt()),
                'expiresAt' => self::formatDateTimeOrNull($a->getExpiresAt()),
                'liftedAt' => self::formatDateTimeOrNull($a->getLiftedAt()),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notifications(User $user): array
    {
        /** @var Notification[] $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.recipient = :u')
            ->setParameter('u', $user)
            ->orderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $n) {
            $out[] = [
                'type' => $n->getType()->value,
                'isRead' => $n->getIsRead(),
                'authorName' => $n->getAuthorName(),
                'communityIdentifier' => $n->getCommunityIdentifier(),
                'channelIdentifier' => $n->getChannelIdentifier(),
                'conversationIdentifier' => $n->getConversationIdentifier(),
                'messageIri' => $n->getMessageIri(),
                'messageCount' => $n->getMessageCount(),
                'createdAt' => self::formatDateTimeOrNull($n->getCreatedAt()),
            ];
        }

        return $out;
    }

    private static function formatDateTimeOrNull(?\DateTimeInterface $dt): ?string
    {
        return $dt?->format(\DateTimeInterface::ATOM);
    }
}
