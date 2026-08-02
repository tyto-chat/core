<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Enum\Notification\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailDigestService implements EmailDigestServiceInterface
{
    /** @var list<NotificationType> */
    public const array DIGEST_TYPES = [
        NotificationType::Mention,
        NotificationType::DmMessage,
        NotificationType::GroupAdded,
        NotificationType::GroupOwnershipTransferred,
        NotificationType::ChannelAccess,
        NotificationType::ChannelModerator,
    ];

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function dispatchDue(): int
    {
        $candidates = $this->notificationRepository->findDigestCandidates(self::DIGEST_TYPES);
        $sent = 0;

        foreach ($candidates as $notifications) {
            $recipient = $notifications[0]->getRecipient();
            $email = $recipient?->getEmail();
            if (null === $recipient || null === $email || '' === $email) {
                continue;
            }

            try {
                $locale = $recipient->getLocale();
                $count = count($notifications);

                $message = new TemplatedEmail()
                    ->to($email)
                    ->subject($this->translator->trans('digest.subject', ['%count%' => $count], 'emails', $locale))
                    ->htmlTemplate('emails/notification_digest.html.twig')
                    ->locale($locale)
                    ->context([
                        'name' => $recipient->getProfile()->getName(),
                        'count' => $count,
                        'items' => $this->buildItems($notifications, $locale),
                    ]);

                $this->mailer->send($message);
            } catch (\Throwable $e) {
                $this->logger->error('digest.send_failed', [
                    'recipient_id' => $recipient->getId(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $now = new \DateTimeImmutable();
            foreach ($notifications as $notification) {
                $notification->setEmailedAt($now);
            }
            $this->entityManager->flush();
            ++$sent;
        }

        return $sent;
    }

    /**
     * @param list<Notification> $notifications
     *
     * @return list<string>
     */
    private function buildItems(array $notifications, string $locale): array
    {
        $items = [];
        foreach ($notifications as $notification) {
            $items[] = $this->translator->trans(
                'digest.line.'.$notification->getType()->value,
                [
                    '%author%' => $notification->getAuthorName(),
                    '%channel%' => $notification->getChannelIdentifier(),
                ],
                'emails',
                $locale,
            );
        }

        return $items;
    }
}
