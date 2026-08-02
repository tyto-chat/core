<?php

declare(strict_types=1);

namespace App\Service\Retention;

use App\Async\RemoveMessageDocumentMessage;
use App\Entity\Message;
use App\Entity\MessageRevision;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use App\Service\AbstractDoctrineService;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\Messenger\MessageBusInterface;

class RetentionService extends AbstractDoctrineService implements RetentionServiceInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly SettingsServiceInterface $settings,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[\Override]
    public function purge(): array
    {
        return [
            'messages' => $this->purgeMessages(),
            'attachments' => $this->purgeAttachments(),
            'notifications' => $this->purgeNotifications(),
        ];
    }

    /**
     * Attachments outlive their retention window even when the message body is
     * still within its own — files are the bulk of stored personal data, so they
     * get an independent, usually shorter, clock. The message itself is left
     * intact; only the files and their MediaObject rows go.
     */
    private function purgeAttachments(): int
    {
        $days = (int) $this->settings->get(Settings::defaultAttachmentRetentionDays());
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days));
        $total = 0;

        do {
            $batch = $this->messageRepository->findWithAttachmentsOlderThanForPurge($cutoff, self::BATCH_SIZE);
            foreach ($batch as $message) {
                $this->mediaObjectService->deleteMessageAttachments($message);
                ++$total;
            }
            $this->flush();
            $this->clear();
        } while (self::BATCH_SIZE === \count($batch));

        return $total;
    }

    private function purgeMessages(): int
    {
        $days = (int) $this->settings->get(Settings::messageRetentionDays());
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days));
        $total = 0;

        do {
            $batch = $this->messageRepository->findOlderThanForPurge($cutoff, self::BATCH_SIZE);
            foreach ($batch as $message) {
                $this->redact($message);
                ++$total;
            }
            $this->flush();
            $this->clear();
        } while (self::BATCH_SIZE === \count($batch));

        do {
            $batch = $this->messageRepository->findDeletedWithBodiesForPurge($cutoff, self::BATCH_SIZE);
            foreach ($batch as $message) {
                $this->redact($message);
                ++$total;
            }
            $this->flush();
            $this->clear();
        } while (self::BATCH_SIZE === \count($batch));

        return $total;
    }

    private function redact(Message $message): void
    {
        $this->mediaObjectService->deleteMessageAttachments($message);

        foreach ($message->getRevisions() as $revision) {
            \assert($revision instanceof MessageRevision);
            $revision->setText('');
        }

        $message->setDeleted(true);
        if (null === $message->getDeletedAt()) {
            $message->setDeletedAt(new \DateTime());
        }
        $this->persist($message);

        $this->messageBus->dispatch(new RemoveMessageDocumentMessage($message->getId()));
    }

    private function purgeNotifications(): int
    {
        $days = (int) $this->settings->get(Settings::notificationRetentionDays());
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days));

        return $this->notificationRepository->deleteOlderThan($cutoff);
    }
}
