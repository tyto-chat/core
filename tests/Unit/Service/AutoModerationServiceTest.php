<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Community;
use App\Entity\User;
use App\Repository\ModerationActionRepository;
use App\Service\Moderation\AutoModerationService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AutoModerationServiceTest extends TestCase
{
    private ModerationActionRepository&MockObject $moderationActionRepository;
    private EntityManagerInterface&MockObject $entityManager;

    private function buildService(int $duration = 60, bool $progressive = false, int $reset = 10, int $max = 86400): AutoModerationService
    {
        $this->moderationActionRepository = $this->createMock(ModerationActionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $settingsMock = $this->createMock(SettingsServiceInterface::class);
        $settingsMock->method('get')->willReturnCallback(static function (mixed $def) use ($duration, $progressive, $reset, $max): mixed {
            return match ($def->key) {
                Settings::autoTimeoutDurationSeconds()->key => $duration,
                Settings::autoTimeoutProgressive()->key => $progressive,
                Settings::autoTimeoutResetSeconds()->key => $reset,
                Settings::autoTimeoutMaxSeconds()->key => $max,
                default => throw new \LogicException('Unexpected setting key: '.$def->key),
            };
        });

        $service = new AutoModerationService($this->moderationActionRepository, $settingsMock);
        $service->setEntityManager($this->entityManager);
        $service->setLogger(new NullLogger());

        return $service;
    }

    public function testComputeReturnsBaseDurationWhenNotProgressive(): void
    {
        $service = $this->buildService(duration: 60, progressive: false);
        $community = $this->createMock(Community::class);
        $user = $this->createMock(User::class);

        $result = $service->computeAutoTimeoutDuration($community, $user);

        self::assertGreaterThanOrEqual(new \DateTimeImmutable('+59 seconds'), $result);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+61 seconds'), $result);
    }

    public function testComputeTripliesOnFirstOffense(): void
    {
        $service = $this->buildService(duration: 60, progressive: true, reset: 10);
        $community = $this->createMock(Community::class);
        $user = $this->createMock(User::class);

        $this->moderationActionRepository->method('countBotTimeoutsByUserAndCommunity')->willReturn(1);

        $result = $service->computeAutoTimeoutDuration($community, $user);

        self::assertGreaterThanOrEqual(new \DateTimeImmutable('+179 seconds'), $result);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+181 seconds'), $result);
    }

    public function testComputeTenXOnRepeatOffense(): void
    {
        $service = $this->buildService(duration: 60, progressive: true, reset: 10);
        $community = $this->createMock(Community::class);
        $user = $this->createMock(User::class);

        $this->moderationActionRepository->method('countBotTimeoutsByUserAndCommunity')->willReturn(5);

        $result = $service->computeAutoTimeoutDuration($community, $user);

        self::assertGreaterThanOrEqual(new \DateTimeImmutable('+599 seconds'), $result);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+601 seconds'), $result);
    }

    public function testComputeClampsToMaxOnRepeatOffense(): void
    {
        // base 60 × 10 = 600, but the ceiling caps it at 120.
        $service = $this->buildService(duration: 60, progressive: true, reset: 10, max: 120);
        $community = $this->createMock(Community::class);
        $user = $this->createMock(User::class);

        $this->moderationActionRepository->method('countBotTimeoutsByUserAndCommunity')->willReturn(5);

        $result = $service->computeAutoTimeoutDuration($community, $user);

        self::assertGreaterThanOrEqual(new \DateTimeImmutable('+119 seconds'), $result);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+121 seconds'), $result);
    }
}
