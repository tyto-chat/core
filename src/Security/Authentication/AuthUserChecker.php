<?php

declare(strict_types=1);

namespace App\Security\Authentication;

use App\Entity\User;
use App\Service\Moderation\ModerationServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuthUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly ModerationServiceInterface $moderationService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isBot() && !$this->isApiKeyRequest()) {
            throw new DisabledException('Bot users can only authenticate via API key.');
        }

        if ($this->moderationService->isServerBanned($user)) {
            throw new DisabledException('Account is server-wide banned.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }

    private function isApiKeyRequest(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $request->attributes->has(ApiKeyAuthenticator::REQUEST_KEY_ATTRIBUTE);
    }
}
