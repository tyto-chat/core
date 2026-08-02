<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Enum\Message\MessageKind;
use App\Exception\User\UserNotFoundException;
use App\Service\User\BotUserServiceInterface;
use App\Service\UserContextServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class WelcomeMessageService implements WelcomeMessageServiceInterface
{
    private const int TEMPLATE_COUNT = 5;

    public function __construct(
        private readonly BotUserServiceInterface $botUserService,
        private readonly UserContextServiceInterface $userContextService,
        private readonly MessageServiceInterface $messageService,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function sendIfConfigured(Community $community, User $newMember): void
    {
        $channel = $community->getWelcomeChannel();
        if (null === $channel) {
            return;
        }

        if ($channel->isPrivate() || ChannelType::Text !== $channel->getType() || $channel->isArchived()) {
            return;
        }
        if ($channel->getCommunity()?->getId() !== $community->getId()) {
            return;
        }

        try {
            $bot = $this->botUserService->getWelcomeBot();
        } catch (\RuntimeException|UserNotFoundException $e) {
            $this->logger->info('Welcome message skipped: bot unavailable.', ['reason' => $e->getMessage()]);

            return;
        }

        $templateId = random_int(1, self::TEMPLATE_COUNT);
        $userId = $newMember->getId();
        \assert(null !== $userId);
        $rawName = $newMember->getProfile()?->getName() ?? $newMember->getEmail() ?? 'user';
        // Must match the editor's `[@name](user:id)` mention format — the client renderer keys on it; strip `[`/`]` so the link parses.
        $safeName = str_replace(['[', ']'], '', $rawName);
        $mention = sprintf('[@%s](user:%d)', $safeName, $userId);

        $text = $this->translator->trans(
            'welcome.template_'.$templateId,
            ['%user%' => $mention],
            'messages',
            $community->getLocale(),
        );

        $this->userContextService->runAs($bot, function () use ($channel, $text): void {
            $this->messageService->sendToChannel($channel, $text, [], MessageKind::System);
        });
    }
}
