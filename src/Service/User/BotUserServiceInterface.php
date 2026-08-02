<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;
use App\Exception\User\UserNotFoundException;

interface BotUserServiceInterface
{
    /**
     * @throws \RuntimeException     if the defaultBotId setting is 0 or the user is missing
     * @throws UserNotFoundException if the bot user does not exist
     */
    public function getDefault(): User;

    /**
     * @throws UserNotFoundException if the user does not exist or is not a bot
     */
    public function get(int $botUserId): User;

    /**
     * @throws \RuntimeException     when neither the welcome nor default bot is configured
     * @throws UserNotFoundException when the resolved bot user does not exist or is not a bot
     */
    public function getWelcomeBot(): User;

    /**
     * @throws \RuntimeException     when neither the auto-moderator nor default bot is configured
     * @throws UserNotFoundException when the resolved bot user does not exist or is not a bot
     */
    public function getAutoModeratorBot(): User;
}
