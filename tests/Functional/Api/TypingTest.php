<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TypingTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testChannelTypingReturns204ForMember(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('typ-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'typ-ch'])->create();

        $this->plainJsonClient($user)->request('POST', '/api/v1/communities/typ-c/channels/typ-ch/typing');

        self::assertResponseStatusCodeSame(204);
    }

    public function testChannelTypingDeniesNonMemberOnPrivateChannel(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('typ-priv-c')->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'typ-priv-ch'])->create();

        $this->plainJsonClient($outsider)->request('POST', '/api/v1/communities/typ-priv-c/channels/typ-priv-ch/typing');

        self::assertResponseStatusCodeSame(404);
    }

    public function testChannelTypingRequiresAuthentication(): void
    {
        $community = CommunityFactory::new()->withIdentifier('typ-anon-c')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'typ-anon-ch'])->create();

        $this->plainJsonClient()->request('POST', '/api/v1/communities/typ-anon-c/channels/typ-anon-ch/typing');

        self::assertResponseStatusCodeSame(401);
    }

    public function testConversationTypingReturns204ForMember(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        $this->plainJsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/typing');

        self::assertResponseStatusCodeSame(204);
    }

    public function testConversationTypingDeniesNonMember(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $outsider = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        $this->plainJsonClient($outsider)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/typing');

        self::assertResponseStatusCodeSame(403);
    }
}
