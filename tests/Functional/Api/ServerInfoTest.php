<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\MediaObject;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ServerInfoTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /** @return array<string, mixed> */
    private function serverInfo(): array
    {
        return $this->jsonClient()
            ->request('GET', '/api/v1/server-info', ['headers' => ['Accept' => 'application/json']])
            ->toArray();
    }

    public function testCommunityStatsCountMembersAndPublicChannels(): void
    {
        $community = CommunityFactory::new()->withIdentifier('stats-c')->create();
        foreach (UserFactory::createMany(3) as $user) {
            CommunityMemberFactory::createForUserAndCommunity($user, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'stats-pub-1'])->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'stats-pub-2'])->create();
        ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'stats-priv', 'private' => true])->create();
        ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'stats-arch', 'archivedAt' => new \DateTimeImmutable('-1 day')])->create();

        $data = $this->serverInfo();

        self::assertArrayHasKey('communityStats', $data);
        $stats = $data['communityStats']['stats-c'] ?? null;
        self::assertNotNull($stats);
        self::assertSame(3, $stats['memberCount']);
        self::assertSame(2, $stats['channelCount']);
        self::assertArrayHasKey('onlineCount', $stats);
    }

    public function testPrivateCommunityAbsentFromStats(): void
    {
        CommunityFactory::new()->withIdentifier('stats-hidden')->with(['private' => true])->create();

        $data = $this->serverInfo();

        self::assertArrayNotHasKey('stats-hidden', $data['communityStats'] ?? []);
    }

    public function testCommunityLogoGetsSignedUrlsInPlainJson(): void
    {
        $community = CommunityFactory::new()->withIdentifier('logo-c')->create();
        $logo = new MediaObject();
        $logo->filePath = 'logo-test.png';
        $logo->type = 'logo';
        $this->em()->persist($logo);
        $community->setLogo($logo);
        $this->em()->flush();

        $data = $this->serverInfo();

        $entry = null;
        foreach ($data['communities'] as $candidate) {
            if ('logo-c' === $candidate['identifier']) {
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry);
        self::assertIsArray($entry['logo']['contentUrl']);
        self::assertStringContainsString('/media/', $entry['logo']['contentUrl']['sm']);
    }
}
