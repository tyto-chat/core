<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ConversationBlockTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private const MESSAGE = "You can't start a conversation with these people.";

    /** @return list<User> */
    private function usersSharingCommunity(int $count): array
    {
        $community = CommunityFactory::createOne();
        $users = [];
        for ($i = 0; $i < $count; ++$i) {
            $user = UserFactory::createOne();
            CommunityMemberFactory::createForUserAndCommunity($user, $community);
            $users[] = $user;
        }

        return $users;
    }

    private function block(User $blocker, User $target): void
    {
        $this->plainJsonClient($blocker)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $target->getId()]]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param list<User> $members
     *
     * @return array<string, mixed>
     */
    private function createConversation(User $as, array $members): array
    {
        return $this->jsonClient($as)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => array_map(static fn (User $u): ?int => $u->getId(), $members)],
        ])->toArray(false);
    }

    public function testBlockerCannotCreateDmWithBlocked(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->block($a, $b);

        $body = $this->createConversation($a, [$b]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::MESSAGE, $body['error']);
    }

    public function testBlockedCannotCreateDmWithBlocker(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->block($a, $b);

        $body = $this->createConversation($b, [$a]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::MESSAGE, $body['error']);
    }

    public function testExistingConversationIsReturnedDespiteBlock(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $first = $this->createConversation($a, [$b]);
        $this->block($a, $b);

        $second = $this->createConversation($a, [$b]);

        self::assertResponseIsSuccessful();
        self::assertSame($first['identifier'], $second['identifier']);
    }

    public function testGroupWithBlockedParticipantRefused(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->block($a, $c);

        $body = $this->createConversation($a, [$b, $c]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::MESSAGE, $body['error']);
    }

    public function testThirdPartyCanCreateGroupContainingBlockedPair(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->block($a, $c);

        $body = $this->createConversation($b, [$a, $c]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(3, $body['members']);
    }

    public function testStaffExemptBlockDoesNotPreventCreation(): void
    {
        $a = UserFactory::createOne();
        $m = UserFactory::createOne();
        $this->block($a, $m);
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createModeratorForCommunity($m, $community);

        $this->createConversation($a, [$m]);

        self::assertResponseStatusCodeSame(201);
    }
}
