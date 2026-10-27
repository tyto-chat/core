<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\SendWebPushMessage;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\WebPushSenderInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\UserBlockServiceInterface;
use App\Settings\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendWebPushHandler
{
    public function __construct(
        private readonly PushSubscriptionRepository $repository,
        private readonly WebPushSenderInterface $sender,
        private readonly TranslatorInterface $translator,
        private readonly SettingsServiceInterface $settings,
        private readonly LoggerInterface $logger,
        private readonly UserBlockServiceInterface $userBlocks,
    ) {
    }

    public function __invoke(SendWebPushMessage $message): void
    {
        if (!$this->sender->isConfigured() || $this->actorIsBlocked($message)) {
            return;
        }

        $subscriptions = $this->repository->findByUserId($message->recipientUserId);
        if ([] === $subscriptions) {
            return;
        }

        $title = (string) $this->settings->get(Settings::serverName());

        $deadEndpoints = [];
        foreach ($subscriptions as $subscription) {
            $body = $this->translator->trans(
                $message->bodyKey,
                $message->bodyParams,
                'messages',
                $subscription->getLocale(),
            );

            try {
                $alive = $this->sender->send($subscription, [
                    'title' => $title,
                    'body' => $body,
                    'url' => $message->url,
                    'tag' => $message->tag,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('webpush.send_failed', [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (!$alive) {
                $deadEndpoints[] = $subscription->getEndpoint();
            }
        }

        $this->repository->deleteByEndpoints($deadEndpoints);
    }

    private function actorIsBlocked(SendWebPushMessage $message): bool
    {
        $actorId = $message->actorUserId ?? null;
        if (null === $actorId) {
            return false;
        }
        $blocked = $this->userBlocks->enforcedBlockedIdsByBlocker([$message->recipientUserId])[$message->recipientUserId] ?? [];

        return in_array($actorId, $blocked, true);
    }
}
