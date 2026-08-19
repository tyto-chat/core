<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class InvitableUsersTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testSearchReturnsUsersFromSharedCommunities(): void
    {
        $caller = UserFactory::createOne();
        $shared = UserFactory::new()
            ->afterInstantiate(function (\App\Entity\User $u): void {
                $u->getProfile()->setName('Zebra Findable');
            })
            ->create();
        $stranger = UserFactory::new()
            ->afterInstantiate(function (\App\Entity\User $u): void {
                $u->getProfile()->setName('Zebra Stranger');
            })
            ->create();

        $community = CommunityFactory::new()->withIdentifier('inv-shared')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        CommunityMemberFactory::createForUserAndCommunity($shared, $community);

        $response = $this->plainJsonClient($caller)->request('GET', '/api/v1/me/invitable-users?search=Zebra');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $ids = array_column($data['items'], 'id');

        self::assertContains($shared->getId(), $ids);
        self::assertNotContains($stranger->getId(), $ids);
        self::assertNotContains($caller->getId(), $ids);

        foreach ($data['items'] as $item) {
            self::assertArrayNotHasKey('email', $item, 'Invitable-user search must never leak email addresses.');
        }
    }

    public function testGlobalAdminSearchSeesUsersWithoutSharedCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $stranger = UserFactory::new()
            ->afterInstantiate(function (\App\Entity\User $u): void {
                $u->getProfile()->setName('Findable Stranger');
            })
            ->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/me/invitable-users?search=Findable');

        self::assertResponseIsSuccessful();
        $ids = array_column($response->toArray()['items'], 'id');

        self::assertContains($stranger->getId(), $ids);
    }

    public function testEmptySearchSuggestsConversationPartnersThenMentioners(): void
    {
        $caller = UserFactory::createOne();
        $olderPartner = UserFactory::createOne();
        $newerPartner = UserFactory::createOne();
        $mentioner = UserFactory::createOne();
        $bystander = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('inv-sugg')->create();
        foreach ([$caller, $olderPartner, $newerPartner, $mentioner, $bystander] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }

        $olderConversation = ConversationFactory::new()
            ->withParticipants([$caller, $olderPartner])
            ->with(['lastMessageAt' => new \DateTimeImmutable('-2 days')])
            ->create();
        ConversationMemberFactory::createForUserAndConversation($caller, $olderConversation);
        ConversationMemberFactory::createForUserAndConversation($olderPartner, $olderConversation);
        $newerConversation = ConversationFactory::new()
            ->withParticipants([$caller, $newerPartner])
            ->with(['lastMessageAt' => new \DateTimeImmutable('-1 hour')])
            ->create();
        ConversationMemberFactory::createForUserAndConversation($caller, $newerConversation);
        ConversationMemberFactory::createForUserAndConversation($newerPartner, $newerConversation);

        $channel = \App\Tests\Factory\ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'sugg-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $mentionMessage = MessageFactory::new()->inPage($page)->byUser($mentioner)->create();
        NotificationFactory::new()
            ->forRecipient($caller)
            ->inCommunity($community)
            ->with(['messageIri' => '/api/v1/messages/'.$mentionMessage->getId()])
            ->create();

        $response = $this->plainJsonClient($caller)->request('GET', '/api/v1/me/invitable-users');

        self::assertResponseIsSuccessful();
        $ids = array_column($response->toArray()['items'], 'id');

        self::assertSame(
            [$newerPartner->getId(), $olderPartner->getId(), $mentioner->getId()],
            $ids,
        );
        self::assertNotContains($bystander->getId(), $ids);
    }

    public function testEmptySearchReturnsNothingWithoutContactSignals(): void
    {
        $caller = UserFactory::createOne();
        $shared = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('inv-empty')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        CommunityMemberFactory::createForUserAndCommunity($shared, $community);

        $response = $this->plainJsonClient($caller)->request('GET', '/api/v1/me/invitable-users');

        self::assertResponseIsSuccessful();
        self::assertSame([], $response->toArray()['items']);
    }

    public function testEmptySearchExcludesMentionerWithoutSharedCommunity(): void
    {
        $caller = UserFactory::createOne();
        $exMentioner = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('inv-gone')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);

        $otherCommunity = CommunityFactory::new()->withIdentifier('inv-other')->create();
        CommunityMemberFactory::createForUserAndCommunity($exMentioner, $otherCommunity);

        $channel = \App\Tests\Factory\ChannelFactory::new()->inCommunity($otherCommunity)->with(['identifier' => 'gone-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $mentionMessage = MessageFactory::new()->inPage($page)->byUser($exMentioner)->create();
        NotificationFactory::new()
            ->forRecipient($caller)
            ->with(['messageIri' => '/api/v1/messages/'.$mentionMessage->getId()])
            ->create();

        $response = $this->plainJsonClient($caller)->request('GET', '/api/v1/me/invitable-users');

        self::assertResponseIsSuccessful();
        self::assertSame([], $response->toArray()['items']);
    }

    public function testRequiresAuthentication(): void
    {
        $this->plainJsonClient()->request('GET', '/api/v1/me/invitable-users');

        self::assertResponseStatusCodeSame(401);
    }
}
