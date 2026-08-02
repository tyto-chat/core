<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MessagePage;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Service\Message\MessageServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MessagePinTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, Community, Channel, MessagePage} */
    private function setupFixture(string $communityId = 'pin-c', string $channelId = 'pin-ch'): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityId)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => $channelId])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();

        return [$user, $community, $channel, $page];
    }

    public function testChannelModeratorCanPin(): void
    {
        [$author, $community, $channel, $page] = $this->setupFixture();
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($mod)->request('POST', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['pinned' => true]);
    }

    public function testCommunityModeratorCanPinWithoutChannelRole(): void
    {
        [$author, $community, , $page] = $this->setupFixture('pin-cm');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($mod)->request('POST', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['pinned' => true]);
    }

    public function testCommunityAdminCanPin(): void
    {
        [$author, $community, , $page] = $this->setupFixture('pin-ca');
        $admin = UserFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['pinned' => true]);
    }

    public function testPlainMemberCannotPin(): void
    {
        [$author, , , $page] = $this->setupFixture('pin-pm');
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($author)->request('POST', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotPin(): void
    {
        [$author, , , $page] = $this->setupFixture('pin-anon');
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient()->request('POST', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPinningAlreadyPinnedReturns422(): void
    {
        [$author, $community, $channel, $page] = $this->setupFixture('pin-dup');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $message = MessageFactory::new()->inPage($page)->byUser($author)
            ->with(['pinnedAt' => new \DateTimeImmutable(), 'pinnedBy' => $author])->create();

        $this->jsonClient($mod)->request('POST', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnpin(): void
    {
        [$author, $community, $channel, $page] = $this->setupFixture('pin-unpin');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $message = MessageFactory::new()->inPage($page)->byUser($author)
            ->with(['pinnedAt' => new \DateTimeImmutable(), 'pinnedBy' => $author])->create();

        $this->jsonClient($mod)->request('DELETE', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseStatusCodeSame(204);

        $check = $this->jsonClient($mod)->request('GET', '/api/v1/messages/'.$message->getId());
        self::assertFalse($check->toArray()['pinned']);
    }

    public function testUnpinningNotPinnedReturns422(): void
    {
        [$author, $community, $channel, $page] = $this->setupFixture('pin-unpin-no');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($mod)->request('DELETE', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseStatusCodeSame(422);
    }

    public function testPinCapReturns422(): void
    {
        [$author, $community, $channel, $page] = $this->setupFixture('pin-cap');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);

        // Pre-pin exactly the cap, then attempt one more via the API.
        for ($i = 0; $i < MessageServiceInterface::MAX_PINNED_PER_CHANNEL; ++$i) {
            MessageFactory::new()->inPage($page)->byUser($author)
                ->with(['pinnedAt' => new \DateTimeImmutable(), 'pinnedBy' => $author])->create();
        }
        $extra = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($mod)->request('POST', '/api/v1/messages/'.$extra->getId().'/pin');

        self::assertResponseStatusCodeSame(422);
    }

    public function testListReturnsPinnedNewestFirstExcludingDeleted(): void
    {
        [$author, , , $page] = $this->setupFixture('pin-list');

        MessageFactory::new()->inPage($page)->byUser($author)->withText('older')
            ->with(['pinnedAt' => new \DateTimeImmutable('-2 hours'), 'pinnedBy' => $author])->create();
        MessageFactory::new()->inPage($page)->byUser($author)->withText('newer')
            ->with(['pinnedAt' => new \DateTimeImmutable('-1 hour'), 'pinnedBy' => $author])->create();
        // Pinned but deleted — must be excluded.
        MessageFactory::new()->inPage($page)->byUser($author)->withText('gone')
            ->with(['pinnedAt' => new \DateTimeImmutable(), 'pinnedBy' => $author, 'deleted' => true])->create();
        // Not pinned — must be excluded.
        MessageFactory::new()->inPage($page)->byUser($author)->withText('plain')->create();

        $response = $this->jsonClient($author)->request('GET', '/api/v1/communities/pin-list/channels/pin-ch/pinned-messages');

        self::assertResponseIsSuccessful();
        $items = $response->toArray()['member'] ?? $response->toArray()['hydra:member'] ?? [];
        self::assertCount(2, $items);
        self::assertSame('newer', $items[0]['text']);
        self::assertSame('older', $items[1]['text']);
    }

    public function testAnonymousCannotListPinnedInPrivateChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('pin-priv')->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'vault'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/pin-priv/channels/vault/pinned-messages');

        self::assertResponseStatusCodeSame(401);
    }
}
