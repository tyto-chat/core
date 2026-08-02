<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Community\CommunityRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityEmojiFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityEmojiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAnonymousCanListEmojisForPublicCommunity(): void
    {
        $community = CommunityFactory::new()->withIdentifier('public-emoji-com')->create();
        CommunityEmojiFactory::new()->with([
            'community' => $community,
            'shortcode' => ':thumbs_up:',
        ])->create();

        $response = $this->jsonClient()->request('GET', '/api/v1/communities/public-emoji-com/emojis');

        self::assertResponseIsSuccessful();
        $payload = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['hydra:totalItems']);
        $shortcodes = array_column($payload['hydra:member'], 'shortcode');
        self::assertContains(':thumbs_up:', $shortcodes);
    }

    public function testAnonymousCannotListEmojisForPrivateCommunity(): void
    {
        CommunityFactory::new()->private()->withIdentifier('private-emoji-com')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/private-emoji-com/emojis');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberCanListEmojisForPrivateCommunity(): void
    {
        $community = CommunityFactory::new()->private()->withIdentifier('priv-member-com')->create();
        CommunityEmojiFactory::new()->with(['community' => $community, 'shortcode' => ':heart:'])->create();
        $user = UserFactory::new()->create();
        CommunityMemberFactory::new()->with(['user' => $user, 'community' => $community])->create();

        $this->jsonClient($user)->request('GET', '/api/v1/communities/priv-member-com/emojis');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['hydra:totalItems' => 1]);
    }

    public function testNonMemberCannotListEmojisForPrivateCommunity(): void
    {
        CommunityFactory::new()->private()->withIdentifier('priv-nonmember-com')->create();
        $stranger = UserFactory::new()->create();

        $this->jsonClient($stranger)->request('GET', '/api/v1/communities/priv-nonmember-com/emojis');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownCommunityReturns404(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/communities/no-such-community/emojis');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCustomEmojisAppearInList(): void
    {
        $community = CommunityFactory::new()->withIdentifier('custom-emoji-com')->create();
        CommunityEmojiFactory::new()->with([
            'community' => $community,
            'shortcode' => ':unicorn:',
        ])->create();

        $response = $this->jsonClient()->request('GET', '/api/v1/communities/custom-emoji-com/emojis');

        self::assertResponseIsSuccessful();
        $payload = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['hydra:totalItems']);
        $shortcodes = array_column($payload['hydra:member'], 'shortcode');
        self::assertContains(':unicorn:', $shortcodes);
    }

    public function testCommunityAdminCanDeleteUnusedEmoji(): void
    {
        $community = CommunityFactory::new()->withIdentifier('delete-unused-com')->create();
        $admin = UserFactory::new()->create();
        CommunityMemberFactory::new()->with(['user' => $admin, 'community' => $community, 'role' => CommunityRole::Admin])->create();
        $emoji = CommunityEmojiFactory::new()->with([
            'community' => $community,
            'shortcode' => ':unicorn:',
        ])->create();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/community_emojis/'.$emoji->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testDeleteEmojiInUseCascadesReactionRemoval(): void
    {
        $admin = UserFactory::new()->create();
        $community = CommunityFactory::new()->withIdentifier('delete-in-use-com')->create();
        CommunityMemberFactory::new()->with(['user' => $admin, 'community' => $community, 'role' => CommunityRole::Admin])->create();
        $emoji = CommunityEmojiFactory::new()->with([
            'community' => $community,
            'shortcode' => ':unicorn:',
        ])->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'inuse-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($admin, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($admin)->create();
        $this->jsonClient($admin)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => ':unicorn:'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(201);

        // Deleting the emoji also drops every reaction using its shortcode —
        // the modal asked us not to block on in-use checks.
        $this->jsonClient($admin)->request('DELETE', '/api/v1/community_emojis/'.$emoji->getId());
        self::assertResponseStatusCodeSame(204);

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/messages/'.$message->getId());
        $payload = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $payload['reactions'] ?? []);
    }

    public function testNonAdminCannotDeleteEmoji(): void
    {
        $community = CommunityFactory::new()->withIdentifier('non-admin-delete-com')->create();
        $member = UserFactory::new()->create();
        CommunityMemberFactory::new()->with(['user' => $member, 'community' => $community])->create();
        $emoji = CommunityEmojiFactory::new()->with([
            'community' => $community,
            'shortcode' => ':unicorn:',
        ])->create();

        $this->jsonClient($member)->request('DELETE', '/api/v1/community_emojis/'.$emoji->getId());

        self::assertResponseStatusCodeSame(403);
    }
}
