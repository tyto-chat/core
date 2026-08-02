<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Enum\User\UserRole;
use App\Exception\User\UserNotFoundException;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\AutoModerationServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\BotUserServiceInterface;
use App\Service\UserContextServiceInterface;
use App\Settings\Settings;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
final readonly class AutoTimeoutListener
{
    public function __construct(
        private AutoModerationServiceInterface $autoModerationService,
        private ModerationServiceInterface $moderationService,
        private UserContextServiceInterface $userContext,
        private BotUserServiceInterface $botUserService,
        private CommunityServiceInterface $communityService,
        private Security $security,
        private ClientInterface $redis,
        private LoggerInterface $logger,
        private SettingsServiceInterface $settings,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$this->settings->get(Settings::autoTimeoutEnabled()) || !$event->isMainRequest()) {
            return;
        }

        if (Response::HTTP_TOO_MANY_REQUESTS !== $event->getResponse()->getStatusCode()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $communitySlug = $this->extractCommunitySlug($event->getRequest()->getPathInfo());
        if (null === $communitySlug) {
            return;
        }

        $counterKey = sprintf('auto_timeout:%s:%d', $communitySlug, (int) $user->getId());

        // NX pins the window start and survives a crash between incr and expire.
        $count = (int) $this->redis->incr($counterKey);
        $this->redis->expire($counterKey, (int) $this->settings->get(Settings::autoTimeoutWindowSeconds()), 'NX');

        if ($count < $this->settings->get(Settings::autoTimeoutHits())) {
            return;
        }

        if ($user->isBot() || $user->hasRole(UserRole::Admin->value)) {
            return;
        }

        try {
            $botUser = $this->botUserService->getAutoModeratorBot();
        } catch (\RuntimeException|UserNotFoundException $e) {
            $this->logger->warning('AutoTimeoutListener: auto-moderator bot unavailable, skipping auto-timeout.', [
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $community = $this->communityService->findByIdentifier($communitySlug);
        if (null === $community) {
            return;
        }

        try {
            $expiresAt = $this->autoModerationService->computeAutoTimeoutDuration($community, $user);
            $this->userContext->runAs($botUser, fn () => $this->moderationService->timeout(
                $community,
                $user,
                'Automatic timeout: rate limit exceeded',
                $expiresAt,
            ));

            $this->redis->del($counterKey);
        } catch (\Throwable $e) {
            $this->logger->warning('AutoTimeoutListener: failed to apply auto-timeout.', [
                'userId' => $user->getId(),
                'community' => $communitySlug,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function extractCommunitySlug(string $pathInfo): ?string
    {
        if (preg_match('#^/api/v\d+/communities/([^/]+)/#', $pathInfo, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
