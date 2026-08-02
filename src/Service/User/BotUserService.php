<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;
use App\Exception\User\UserNotFoundException;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\SettingDef;
use App\Settings\Settings;

class BotUserService implements BotUserServiceInterface
{
    public function __construct(
        private readonly UserServiceInterface $userService,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    public function getDefault(): User
    {
        $defaultBotId = $this->settings->get(Settings::defaultBotId());

        if (0 === $defaultBotId) {
            throw new \RuntimeException('The defaultBotId server setting is not configured (value 0). Run: php bin/console tyto:user:create --bot "Tyto Bot"');
        }

        return $this->get($defaultBotId);
    }

    public function getWelcomeBot(): User
    {
        return $this->resolveRole(Settings::welcomeBotId());
    }

    public function getAutoModeratorBot(): User
    {
        return $this->resolveRole(Settings::autoModeratorBotId());
    }

    /** @param SettingDef<int> $roleSetting */
    private function resolveRole(SettingDef $roleSetting): User
    {
        $roleId = $this->settings->get($roleSetting);

        return 0 !== $roleId ? $this->get($roleId) : $this->getDefault();
    }

    public function get(int $botUserId): User
    {
        $user = $this->userService->find($botUserId);

        if (!$user) {
            throw new UserNotFoundException(sprintf('Bot user with id "%d" not found.', $botUserId));
        }

        if (!$user->isBot()) {
            throw new UserNotFoundException(sprintf('User with id "%d" is not a bot user.', $botUserId));
        }

        return $user;
    }
}
