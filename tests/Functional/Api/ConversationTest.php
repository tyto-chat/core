<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Conversation;
use App\Entity\User;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ConversationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, User} */
    private function pairSharingCommunity(string $communityIdent = 'shared-c'): array
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityIdent)->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);

        return [$a, $b];
    }

    public function testCreateConversationWithSharedCommunityParticipant(): void
    {
        [$a, $b] = $this->pairSharingCommunity();

        $response = $this->jsonClient($a)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => [$b->getId()]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertNotEmpty($data['identifier']);
        self::assertCount(2, $data['members']);
    }

    public function testCreateReturnsExistingConversationForSameParticipants(): void
    {
        [$a, $b] = $this->pairSharingCommunity('idempotent-c');

        $first = $this->jsonClient($a)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => [$b->getId()]],
        ])->toArray();

        $second = $this->jsonClient($a)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => [$b->getId()]],
        ])->toArray();

        self::assertSame($first['identifier'], $second['identifier']);
    }

    public function testCreateRejectsParticipantWithoutSharedCommunity(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('only-a')->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);

        $this->jsonClient($a)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => [$b->getId()]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRejectsEmptyParticipantList(): void
    {
        $a = UserFactory::createOne();

        $this->jsonClient($a)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => []],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateIgnoresSelfInList(): void
    {
        [$a, $b] = $this->pairSharingCommunity('self-c');

        // Listing self alongside another participant should produce a 2-member
        // conversation (caller already added; self silently dropped).
        $response = $this->jsonClient($a)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => [$a->getId(), $b->getId()]],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $response->toArray()['members']);
    }

    public function testAnonymousCannotCreateConversation(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => [1]],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testListReturnsCallersConversations(): void
    {
        [$a, $b] = $this->pairSharingCommunity('list-c');
        $other = UserFactory::createOne();
        $community = CommunityFactory::repository()->findOneBy(['identifier' => 'list-c']);
        self::assertNotNull($community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);

        $mine = ConversationFactory::createOne();
        ConversationMemberFactory::createForUserAndConversation($a, $mine);
        ConversationMemberFactory::createForUserAndConversation($b, $mine);

        $notMine = ConversationFactory::createOne();
        ConversationMemberFactory::createForUserAndConversation($b, $notMine);
        ConversationMemberFactory::createForUserAndConversation($other, $notMine);

        $response = $this->jsonClient($a)->request('GET', '/api/v1/conversations');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertCount(1, $data['hydra:member']);
    }

    public function testGetByIdentifierGrantsMember(): void
    {
        [$a, $b] = $this->pairSharingCommunity('detail-c');
        $conversation = $this->makeConvo($a, $b);

        $response = $this->jsonClient($b)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier());

        self::assertResponseIsSuccessful();
        self::assertSame($conversation->getIdentifier(), $response->toArray()['identifier']);
    }

    public function testGetByIdentifierDeniesNonMember(): void
    {
        [$a, $b] = $this->pairSharingCommunity('private-c');
        $outsider = UserFactory::createOne();
        $conversation = $this->makeConvo($a, $b);

        $this->jsonClient($outsider)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier());

        self::assertResponseStatusCodeSame(403);
    }

    public function testMuteAndUnmute(): void
    {
        [$a, $b] = $this->pairSharingCommunity('mute-c');
        $conversation = $this->makeConvo($a, $b);

        $until = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);
        $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mute', [
            'json' => ['mutedUntil' => $until],
        ]);
        self::assertResponseIsSuccessful();

        $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mute', [
            'json' => ['mutedUntil' => null],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testNonMemberCannotMute(): void
    {
        $outsider = UserFactory::createOne();
        [$a, $b] = $this->pairSharingCommunity('mute-deny');
        $conversation = $this->makeConvo($a, $b);

        $this->jsonClient($outsider)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mute', [
            'json' => ['mutedUntil' => null],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testConversationResponseIncludesMemberProfileNames(): void
    {
        [$a, $b] = $this->pairSharingCommunity('profile-c');
        $conversation = $this->makeConvo($a, $b);

        $response = $this->jsonClient($a)->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier(),
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertIsArray($data['members']);
        foreach ($data['members'] as $member) {
            self::assertArrayHasKey('profile', $member);
            self::assertIsArray($member['profile']);
            self::assertArrayHasKey('name', $member['profile']);
            self::assertNotSame('', $member['profile']['name']);
        }
    }

    public function testMarkReadByMember(): void
    {
        [$a, $b] = $this->pairSharingCommunity('mark-read-c');
        $conversation = $this->makeConvo($a, $b);

        $this->jsonClient($b)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mark-read');

        self::assertResponseIsSuccessful();
    }

    public function testNonMemberCannotMarkRead(): void
    {
        $outsider = UserFactory::createOne();
        [$a, $b] = $this->pairSharingCommunity('mark-read-deny');
        $conversation = $this->makeConvo($a, $b);

        $this->jsonClient($outsider)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mark-read');

        self::assertResponseStatusCodeSame(403);
    }

    private function makeConvo(User $a, User $b): Conversation
    {
        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        return $conversation;
    }
}
