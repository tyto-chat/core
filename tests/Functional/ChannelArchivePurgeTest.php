<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\Admin\ServerConfigPatchDto;
use App\Entity\Message;
use App\Entity\MessageRevision;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelArchivePurgeTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testPurgeDeletesOnlyExpiredArchivedChannels(): void
    {
        self::bootKernel();
        $community = CommunityFactory::new()->withIdentifier('purge-c')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'old', 'archivedAt' => new \DateTimeImmutable('-10 days')])->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'fresh', 'archivedAt' => new \DateTimeImmutable('-1 day')])->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'active'])->create();

        $settings = static::getContainer()->get(SettingsServiceInterface::class);
        \assert($settings instanceof SettingsServiceInterface);
        $patch = new ServerConfigPatchDto();
        $patch->archivedChannelRetentionDays = 7;
        $settings->applyPatch($patch);

        $channelService = static::getContainer()->get(ChannelServiceInterface::class);
        \assert($channelService instanceof ChannelServiceInterface);
        $deleted = $channelService->purgeExpiredArchived();

        self::assertSame(1, $deleted);
        self::assertSame(2, ChannelFactory::repository()->count([]));
    }

    public function testPurgeDeletesArchivedChannelWithMessages(): void
    {
        self::bootKernel();
        $community = CommunityFactory::new()->withIdentifier('purge-m')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'old', 'archivedAt' => new \DateTimeImmutable('-10 days')])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->create();
        MessageFactory::new()->inPage($page)->with(['parent' => $root])->create();

        $settings = static::getContainer()->get(SettingsServiceInterface::class);
        \assert($settings instanceof SettingsServiceInterface);
        $patch = new ServerConfigPatchDto();
        $patch->archivedChannelRetentionDays = 7;
        $settings->applyPatch($patch);

        $channelService = static::getContainer()->get(ChannelServiceInterface::class);
        \assert($channelService instanceof ChannelServiceInterface);
        $deleted = $channelService->purgeExpiredArchived();

        self::assertSame(1, $deleted);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        self::assertSame(0, ChannelFactory::repository()->count([]));
        self::assertSame(0, $em->getRepository(Message::class)->count([]));
        self::assertSame(0, $em->getRepository(MessageRevision::class)->count([]));
    }

    public function testPurgeNoOpWhenRetentionZero(): void
    {
        self::bootKernel();
        $community = CommunityFactory::new()->withIdentifier('purge-z')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'old', 'archivedAt' => new \DateTimeImmutable('-100 days')])->create();

        $channelService = static::getContainer()->get(ChannelServiceInterface::class);
        \assert($channelService instanceof ChannelServiceInterface);
        $deleted = $channelService->purgeExpiredArchived();

        self::assertSame(0, $deleted);
        self::assertSame(1, ChannelFactory::repository()->count([]));
    }
}
