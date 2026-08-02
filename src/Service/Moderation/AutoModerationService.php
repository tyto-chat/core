<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Entity\Community;
use App\Entity\User;
use App\Repository\ModerationActionRepository;
use App\Service\AbstractDoctrineService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;

class AutoModerationService extends AbstractDoctrineService implements AutoModerationServiceInterface
{
    public function __construct(
        private readonly ModerationActionRepository $moderationActionRepository,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    #[\Override]
    public function countBotTimeouts(Community $community, User $user): int
    {
        $since = new \DateTimeImmutable(sprintf('-%d seconds', $this->settings->get(Settings::autoTimeoutResetSeconds())));

        return $this->moderationActionRepository->countBotTimeoutsByUserAndCommunity($community, $user, $since);
    }

    #[\Override]
    public function computeAutoTimeoutDuration(Community $community, User $user): \DateTimeImmutable
    {
        $multiplier = 1;

        if ($this->settings->get(Settings::autoTimeoutProgressive())) {
            $count = $this->countBotTimeouts($community, $user);
            $multiplier = match (true) {
                $count >= 2 => 10,
                1 === $count => 3,
                default => 1,
            };
        }

        $seconds = min($this->settings->get(Settings::autoTimeoutDurationSeconds()) * $multiplier, $this->settings->get(Settings::autoTimeoutMaxSeconds()));

        return new \DateTimeImmutable(sprintf('+%d seconds', $seconds));
    }
}
