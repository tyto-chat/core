<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Entity\Conversation;
use App\Entity\User;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class EncryptedConversationTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use Factories;
    use ResetDatabase;

    public function testPlainAndEncryptedDmCoexistForSamePair(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $plain = $this->create($a, [$b], false);
        self::assertResponseStatusCodeSame(201);
        $this->reachable($a, $b);

        $encrypted = $this->create($a, [$b], true);

        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($plain['identifier'], $encrypted['identifier']);
        self::assertFalse($plain['encrypted']);
        self::assertTrue($encrypted['encrypted']);
    }

    public function testEncryptedCreateIsIdempotent(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a, $b);

        $first = $this->create($a, [$b], true);
        $second = $this->create($b, [$a], true);

        self::assertSame($first['identifier'], $second['identifier']);
    }

    public function testParticipantWithoutDeviceRejected(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a);

        $body = $this->create($a, [$b], true);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::nameOf($b), $body['error']);
    }

    public function testCallerWithoutDeviceIsToldToSetUpEncryption(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($b);

        $body = $this->create($a, [$b], true);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('You need the tyto.chat desktop app', $body['error']);
    }

    public function testParticipantWithOnlyRevokedDevicesRejected(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a);
        $this->deviceWithoutUsablePackages($b, revoked: true);

        $this->create($a, [$b], true);

        self::assertResponseStatusCodeSame(422);
    }

    public function testParticipantWithEmptyOrExpiredKeyPackagesRejectedAsUnreachable(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->reachable($a);
        $this->deviceWithoutUsablePackages($b);
        $this->deviceWithoutUsablePackages($c, expired: true);

        $this->create($a, [$b], true);
        self::assertResponseStatusCodeSame(422);
        $this->create($a, [$c], true);
        self::assertResponseStatusCodeSame(422);
    }

    public function testBotParticipantRejected(): void
    {
        [$a] = $this->usersSharingCommunity(1);
        $bot = UserFactory::new()->bot()->create();
        CommunityMemberFactory::createForUserAndCommunity($bot, $this->communityOf($a));
        $this->reachable($a);

        $this->create($a, [$bot], true);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPatCallerRejected(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->reachable($a, $b);
        $pat = $this->apiKeyClient($a);

        $pat->request('POST', '/api/v1/conversations', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['memberUserIds' => [$b->getId()], 'encrypted' => true],
        ]);
        self::assertResponseStatusCodeSame(403);
        $pat->request('POST', '/api/v1/conversations', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['memberUserIds' => [$c->getId()]],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testDisabledModeRejectsEncryptedCreation(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a, $b);
        $this->storeMode('disabled');

        $body = $this->create($a, [$b], true);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Encrypted direct messages are turned off on this server.', $body['error']);
    }

    public function testExistingPlainHashUnchanged(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $ids = [(int) $a->getId(), (int) $b->getId()];
        sort($ids);

        $body = $this->create($a, [$b], false);

        $stored = $this->em()->getRepository(Conversation::class)->findOneBy(['identifier' => $body['identifier']]);
        self::assertInstanceOf(Conversation::class, $stored);
        self::assertSame(sha1(implode('-', $ids)), $stored->getParticipantsHash());
        self::assertSame(sha1(implode('-', $ids)), Conversation::hashParticipants($ids));
        self::assertNotSame(Conversation::hashParticipants($ids), Conversation::hashParticipants($ids, true));
    }

    public function testRequiredModeRejectsPlain(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->storeMode('required');

        $body = $this->create($a, [$b], false);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('This server only allows encrypted direct messages.', $body['error']);
    }

    public function testRequiredModeBlocksGroupDm(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->reachable($a, $b, $c);
        $this->storeMode('required');

        $this->create($a, [$b, $c], false);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEncryptedGroupRejectedInEveryMode(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->reachable($a, $b, $c);

        foreach (['enabled', 'required', 'disabled'] as $mode) {
            $this->storeMode($mode);
            $body = $this->create($a, [$b, $c], true);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Group conversations cannot be encrypted yet.', $body['error'], $mode);
        }
    }

    public function testPlainGroupCreatedUnderEnabledEvenWhenAllReachable(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->reachable($a, $b, $c);

        $body = $this->create($a, [$b, $c], false);

        self::assertResponseStatusCodeSame(201);
        self::assertFalse($body['encrypted']);
    }

    public function testCreatorRefusalRejectsPlainNamingSelf(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->refuses($a);

        $body = $this->create($a, [$b], false);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('You only take part in encrypted direct messages.', $body['error']);
    }

    public function testParticipantRefusalRejectsPlainNamingParticipant(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->refuses($b);

        $body = $this->create($a, [$b], false);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::nameOf($b), $body['error']);
    }

    public function testParticipantRefusalBlocksGroupDm(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->refuses($c);

        $body = $this->create($a, [$b, $c], false);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::nameOf($c), $body['error']);
    }

    public function testEnabledModeEveryoneReachableRejectsPlainRequest(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a, $b);

        $this->create($a, [$b], false);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEnabledModeOneUnreachableAllowsPlain(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a);

        $body = $this->create($a, [$b], false);

        self::assertResponseStatusCodeSame(201);
        self::assertFalse($body['encrypted']);
    }

    public function testDisabledModePlainAllowedUnlessRefusal(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->reachable($a, $b, $c);
        $this->refuses($c);
        $this->storeMode('disabled');

        $this->create($a, [$b], false);
        self::assertResponseStatusCodeSame(201);
        $this->create($a, [$c], false);
        self::assertResponseStatusCodeSame(422);
    }

    public function testBlockedUserErrorTakesPrecedenceOverPolicy(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->refuses($b);
        $this->plainJsonClient($a)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]]);
        self::assertResponseStatusCodeSame(201);

        foreach ([false, true] as $encrypted) {
            $body = $this->create($a, [$b], $encrypted);
            self::assertResponseStatusCodeSame(422);
            self::assertSame("You can't start a conversation with these people.", $body['error']);
        }
    }

    public function testExistingConversationReturnedEvenWhenRulesNowForbidIt(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $plain = $this->create($a, [$b], false);
        $this->storeMode('required');

        $again = $this->create($a, [$b], false);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($plain['identifier'], $again['identifier']);
    }

    public function testConcurrentEncryptedCreateReturnsSameRow(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a, $b);
        $ids = [(int) $a->getId(), (int) $b->getId()];
        $winner = new Conversation(true);
        $winner->setParticipantsHash(Conversation::hashParticipants($ids, true));
        foreach ([$a, $b] as $member) {
            $winner->addMember((new \App\Entity\ConversationMember())->setUser($this->em()->getReference(User::class, $member->getId())));
        }
        $this->em()->persist($winner);
        $this->em()->flush();

        $body = $this->create($a, [$b], true);

        self::assertSame($winner->getIdentifier(), $body['identifier']);
    }

    /**
     * @param list<User> $members
     *
     * @return array<string, mixed>
     */
    private function create(User $as, array $members, bool $encrypted): array
    {
        return $this->plainJsonClient($as)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => array_map(static fn (User $u): ?int => $u->getId(), $members), 'encrypted' => $encrypted],
        ])->toArray(false);
    }

    private static function nameOf(User $user): string
    {
        return (string) $user->getProfile()?->getName();
    }

    private function communityOf(User $user): \App\Entity\Community
    {
        $membership = $this->em()->getRepository(\App\Entity\CommunityMember::class)->findOneBy(['user' => $user->getId()]);
        \assert(null !== $membership);

        return $membership->getCommunity();
    }
}
