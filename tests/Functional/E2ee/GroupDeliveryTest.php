<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Conversation;
use App\Entity\E2eeAccount;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Functional\Conversation\ConversationPolicyFixturesTrait;
use App\Tests\Stub\RecordingMercureHub;
use App\Utils\Topics;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GroupDeliveryTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use E2eeRegistryTrait;
    use EnvelopeFixturesTrait;
    use Factories;
    use ResetDatabase;

    public function testFirstCommitAccepted(): void
    {
        $pair = $this->pair();

        $result = $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($pair['bDevice'])]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $result['epoch']);
        self::assertSame(1, $result['generation']);

        $state = $this->state($pair['b'], $pair['conversation']);
        self::assertSame(['epoch' => 1, 'generation' => 1, 'groupInfo' => base64_encode('group-info-1'), 'frozenGenerations' => []], $state);
    }

    public function testStateOfConversationWithoutGroup(): void
    {
        $pair = $this->pair();

        self::assertSame(['epoch' => 0, 'generation' => 0, 'groupInfo' => null, 'frozenGenerations' => []], $this->state($pair['a'], $pair['conversation']));
    }

    public function testFirstCommitMustStartGenerationOne(): void
    {
        $pair = $this->pair();

        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, generation: 2));

        self::assertResponseStatusCodeSame(422);
    }

    public function testStaleEpochReturns409WithCurrentEpoch(): void
    {
        $pair = $this->pair();
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1));

        $body = $this->commit($pair['b'], $pair['conversation'], $this->commitBody($pair['bDevice'], 1));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_epoch', $body['code']);
        self::assertSame(1, $body['currentEpoch']);

        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($pair['bDevice'], 3));
        self::assertResponseStatusCodeSame(409);

        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($pair['bDevice'], 2));
        self::assertResponseStatusCodeSame(201);
    }

    public function testResendWithSameCommitIdReturnsOriginalResult(): void
    {
        $pair = $this->pair();
        $body = $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($pair['bDevice'])]);
        $first = $this->commit($pair['a'], $pair['conversation'], $body);
        self::assertResponseStatusCodeSame(201);

        $again = $this->commit($pair['a'], $pair['conversation'], $body);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $again);
        self::assertCount(1, $this->commits($pair['a'], $pair['conversation'], 0));
        self::assertCount(1, $this->welcomes($pair['b']));
    }

    public function testCommitIdFromAnotherConversationConflicts(): void
    {
        $pair = $this->pair();
        $other = $this->conversationBetween([$pair['a'], $this->peerWithDevice($pair['a'])['user']], encrypted: true);
        $body = $this->commitBody($pair['aDevice'], 1);
        $this->commit($pair['a'], $pair['conversation'], $body);

        $this->commit($pair['a'], $other, $body);

        self::assertResponseStatusCodeSame(409);
    }

    public function testNonMemberRejected(): void
    {
        $pair = $this->pair();
        $outsider = $this->peerWithDevice($pair['a']);

        $this->commit($outsider['user'], $pair['conversation'], $this->commitBody($outsider['deviceId'], 1));
        self::assertResponseStatusCodeSame(403);

        $this->plainJsonClient($outsider['user'])->request('GET', '/api/v1/conversations/'.$pair['conversation']->getIdentifier().'/mls/state');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCommitFromAnotherUsersDeviceRejected(): void
    {
        $pair = $this->pair();

        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['bDevice'], 1));

        self::assertResponseStatusCodeSame(404);
    }

    public function testCommitsRejectedOnPlainConversation(): void
    {
        $pair = $this->pair();
        $plain = $this->conversationBetween([$pair['a'], $pair['b']]);

        $this->commit($pair['a'], $plain, $this->commitBody($pair['aDevice'], 1));

        self::assertResponseStatusCodeSame(422);
    }

    public function testWelcomeOnlyForTargetDevice(): void
    {
        $pair = $this->pair();
        $bSecond = $this->registerDevice($this->plainJsonClient($pair['b']), $pair['bKeys'], $pair['b'], 'Desktop')['body']['deviceId'];
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($pair['bDevice'], 'for-b')]));

        $welcomes = $this->welcomes($pair['b']);
        self::assertCount(1, $welcomes);
        self::assertSame($pair['bDevice'], $welcomes[0]['deviceId']);
        self::assertSame(base64_encode('for-b'), $welcomes[0]['data']);
        self::assertSame($pair['conversation']->getIdentifier(), $welcomes[0]['conversation']);
        self::assertSame(1, $welcomes[0]['epoch']);
        self::assertSame([], $this->welcomes($pair['b'], $bSecond));
        self::assertSame([], $this->welcomes($pair['a']));

        $this->plainJsonClient($pair['a'])->request('POST', '/api/v1/e2ee/welcomes/'.$welcomes[0]['id'].'/ack');
        self::assertResponseStatusCodeSame(404);

        $this->plainJsonClient($pair['b'])->request('POST', '/api/v1/e2ee/welcomes/'.$welcomes[0]['id'].'/ack');
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->welcomes($pair['b']));

        $this->plainJsonClient($pair['b'])->request('POST', '/api/v1/e2ee/welcomes/'.$welcomes[0]['id'].'/ack');
        self::assertResponseStatusCodeSame(204);
    }

    public function testWelcomeForDeviceOutsideConversationRejected(): void
    {
        $pair = $this->pair();
        $outsider = $this->peerWithDevice($pair['a']);

        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($outsider['deviceId'])]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testWelcomesListedAtMostFiftyPerCall(): void
    {
        $pair = $this->pair();
        for ($epoch = 1; $epoch <= 51; ++$epoch) {
            $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], $epoch, welcomes: [$this->welcome($pair['bDevice'], 'w'.$epoch)]));
            self::assertResponseStatusCodeSame(201);
        }

        $first = $this->welcomes($pair['b']);
        self::assertCount(50, $first);
        self::assertSame(1, $first[0]['epoch']);
        foreach ($first as $welcome) {
            $this->plainJsonClient($pair['b'])->request('POST', '/api/v1/e2ee/welcomes/'.$welcome['id'].'/ack');
        }

        $rest = $this->welcomes($pair['b']);
        self::assertCount(1, $rest);
        self::assertSame(51, $rest[0]['epoch']);
    }

    public function testWelcomesPageAfterCursorWithoutAcknowledging(): void
    {
        $pair = $this->pair();
        for ($epoch = 1; $epoch <= 51; ++$epoch) {
            $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], $epoch, welcomes: [$this->welcome($pair['bDevice'], 'w'.$epoch)]));
            self::assertResponseStatusCodeSame(201);
        }
        $first = $this->welcomes($pair['b']);
        $last = $first[49]['id'];

        $rest = $this->welcomes($pair['b'], query: '?after='.$last);

        self::assertCount(1, $rest);
        self::assertSame(51, $rest[0]['epoch']);
        self::assertCount(50, $this->welcomes($pair['b']));
    }

    public function testWelcomeCursorOfAnotherUserNotFound(): void
    {
        $pair = $this->pair();
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($pair['bDevice'])]));
        $foreign = $this->welcomes($pair['b'])[0]['id'];

        $this->plainJsonClient($pair['a'])->request('GET', '/api/v1/e2ee/welcomes?after='.$foreign);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRevokedDeviceGetsNoWelcome(): void
    {
        $pair = $this->pair();
        $bClient = $this->plainJsonClient($pair['b']);
        $bSecond = $this->registerDevice($bClient, $pair['bKeys'], $pair['b'], 'Desktop');
        $this->revoke($pair['b'], $bSecond['body']['deviceId'], $pair['bDevice'], $pair['bKeyPair']);

        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($bSecond['body']['deviceId'])]));
        self::assertResponseStatusCodeSame(422);

        $bThird = $this->registerDevice($this->plainJsonClient($pair['b']), $pair['bKeys'], $pair['b'], 'Tablet');
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($bThird['body']['deviceId'])]));
        self::assertResponseStatusCodeSame(201);
        $this->revoke($pair['b'], $bThird['body']['deviceId'], $pair['bDevice'], $pair['bKeyPair']);

        self::assertSame([], $this->welcomes($pair['b']));
    }

    public function testCommitPublishedToConversationTopicAndWelcomeToUserEvents(): void
    {
        $pair = $this->pair();
        RecordingMercureHub::reset();

        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($pair['bDevice'])]));
        self::assertResponseStatusCodeSame(201);

        $commitEvents = $this->events(Topics::conversation((int) $pair['conversation']->getId()), 'mls.commit');
        self::assertSame([['type' => 'mls.commit', 'conversationIdentifier' => $pair['conversation']->getIdentifier(), 'epoch' => 1, 'generation' => 1]], $commitEvents);

        $welcomeEvents = $this->events(Topics::userEvents((int) $pair['b']->getId()), 'mls.welcome');
        self::assertCount(1, $welcomeEvents);
        self::assertSame($pair['bDevice'], $welcomeEvents[0]['deviceId']);
        self::assertSame($pair['conversation']->getIdentifier(), $welcomeEvents[0]['conversationIdentifier']);
        self::assertSame([], $this->events(Topics::userEvents((int) $pair['a']->getId()), 'mls.welcome'));
    }

    public function testCommitsListedAfterEpoch(): void
    {
        $pair = $this->pair();
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1));
        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($pair['bDevice'], 2));

        $commits = $this->commits($pair['a'], $pair['conversation'], 1);

        self::assertCount(1, $commits);
        self::assertSame(2, $commits[0]['epoch']);
        self::assertSame(base64_encode('commit-2'), $commits[0]['commit']);
        self::assertSame($pair['bDevice'], $commits[0]['deviceId']);
        self::assertCount(2, $this->commits($pair['b'], $pair['conversation'], 0));
    }

    public function testGenerationIncrementAllowedOnlyForResetIdentity(): void
    {
        $pair = $this->pair();
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1));

        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($pair['bDevice'], 2, generation: 2));
        self::assertResponseStatusCodeSame(422);

        $bReset = $this->resetIdentity($pair['b'], $pair['bKeys']);
        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($bReset, 2, generation: 1));
        self::assertResponseStatusCodeSame(422);

        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($bReset, 2, generation: 2));
        self::assertResponseStatusCodeSame(201);

        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($bReset, 3, generation: 3));
        self::assertResponseStatusCodeSame(422);
    }

    public function testOldGenerationFrozenAfterNewOneAccepted(): void
    {
        $pair = $this->pair();
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1));
        $bReset = $this->resetIdentity($pair['b'], $pair['bKeys']);
        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($bReset, 2, generation: 2));

        $state = $this->state($pair['a'], $pair['conversation']);

        self::assertSame(2, $state['epoch']);
        self::assertSame(2, $state['generation']);
        self::assertSame([1], $state['frozenGenerations']);
    }

    public function testMessageWithFrozenGenerationRejected(): void
    {
        $pair = $this->pair();
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1));
        $root = $this->send($pair['a'], $pair['conversation'], self::envelope(1));
        self::assertResponseStatusCodeSame(201);
        $bReset = $this->resetIdentity($pair['b'], $pair['bKeys']);
        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($bReset, 2, generation: 2));

        $this->send($pair['a'], $pair['conversation'], self::envelope(1));
        self::assertResponseStatusCodeSame(422);

        $this->jsonClient($pair['a'])->request('PATCH', $root['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => self::envelope(1)],
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->jsonClient($pair['b'])->request('POST', $root['@id'].'/replies', ['json' => ['text' => self::envelope(1)]]);
        self::assertResponseStatusCodeSame(422);

        $this->send($pair['a'], $pair['conversation'], self::envelope(2));
        self::assertResponseStatusCodeSame(201);
    }

    public function testLeaveRequestRequiresOwnDeviceSignature(): void
    {
        $pair = $this->pair();
        $bSecond = $this->registerDevice($this->plainJsonClient($pair['b']), $pair['bKeys'], $pair['b'], 'Desktop');

        $this->leave($pair['b'], $bSecond['body']['deviceId'], E2eeKeys::leaveRequest($bSecond['body']['deviceId'], $pair['bKeyPair']));
        self::assertResponseStatusCodeSame(422);

        $this->leave($pair['a'], $bSecond['body']['deviceId'], E2eeKeys::leaveRequest($bSecond['body']['deviceId'], $bSecond['keyPair']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testLeaveRequestRevokesDevice(): void
    {
        $pair = $this->pair();
        $bSecond = $this->registerDevice($this->plainJsonClient($pair['b']), $pair['bKeys'], $pair['b'], 'Desktop');
        $request = E2eeKeys::leaveRequest($bSecond['body']['deviceId'], $bSecond['keyPair']);

        $this->leave($pair['b'], $bSecond['body']['deviceId'], $request);
        self::assertResponseStatusCodeSame(204);

        $devices = $this->plainJsonClient($pair['a'])->request('GET', '/api/v1/e2ee/users/'.$pair['b']->getId().'/devices')->toArray();
        self::assertSame([$pair['bDevice']], array_column($devices['devices'], 'id'));

        $revocations = $this->plainJsonClient($pair['a'])->request('GET', '/api/v1/e2ee/users/'.$pair['b']->getId().'/revocations')->toArray()['items'];
        self::assertCount(1, $revocations);
        self::assertSame($request['leaveRequestId'], $revocations[0]['leaveRequestId']);
        self::assertSame($request['requestedAt'], $revocations[0]['revokedAt']);
        self::assertSame($bSecond['body']['deviceId'], $revocations[0]['signerDeviceId']);

        $this->leave($pair['b'], $bSecond['body']['deviceId'], $request);
        self::assertResponseStatusCodeSame(204);
    }

    public function testLeaveRequestListedForParticipantsUntilFulfilled(): void
    {
        $pair = $this->pair();
        $outsider = $this->peerWithDevice($pair['a']);
        $bSecond = $this->registerDevice($this->plainJsonClient($pair['b']), $pair['bKeys'], $pair['b'], 'Desktop');
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, welcomes: [$this->welcome($pair['bDevice']), $this->welcome($bSecond['body']['deviceId'])]));
        RecordingMercureHub::reset();
        $request = E2eeKeys::leaveRequest($bSecond['body']['deviceId'], $bSecond['keyPair']);
        $this->leave($pair['b'], $bSecond['body']['deviceId'], $request);

        self::assertCount(1, $this->events(Topics::conversation((int) $pair['conversation']->getId()), 'mls.leave_request'));
        foreach ([$pair['a'], $pair['b']] as $participant) {
            $pending = $this->leaveRequests($participant, $pair['conversation']);
            self::assertCount(1, $pending);
            self::assertSame($request['leaveRequestId'], $pending[0]['leaveRequestId']);
            self::assertSame($bSecond['body']['deviceId'], $pending[0]['deviceId']);
            self::assertSame((int) $pair['b']->getId(), $pending[0]['userId']);
            self::assertSame(1, $pending[0]['generation']);
            self::assertSame($request['requestedAt'], $pending[0]['requestedAt']);
            self::assertSame($request['signature'], $pending[0]['signature']);
            self::assertSame($bSecond['body']['publicKey'], $pending[0]['devicePublicKey']);
            self::assertSame($bSecond['body']['credential'], $pending[0]['credential']);
        }
        $this->plainJsonClient($outsider['user'])->request('GET', '/api/v1/e2ee/leave-requests?conversation='.$pair['conversation']->getIdentifier());
        self::assertResponseStatusCodeSame(403);

        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 2, leaveRequestId: $request['leaveRequestId']));
        self::assertResponseStatusCodeSame(201);

        self::assertSame([], $this->leaveRequests($pair['a'], $pair['conversation']));
    }

    public function testSecondCommitForSameLeaveRequestConflicts(): void
    {
        $pair = $this->pair();
        $bSecond = $this->registerDevice($this->plainJsonClient($pair['b']), $pair['bKeys'], $pair['b'], 'Desktop');
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1));
        $request = E2eeKeys::leaveRequest($bSecond['body']['deviceId'], $bSecond['keyPair']);
        $this->leave($pair['b'], $bSecond['body']['deviceId'], $request);
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 2, leaveRequestId: $request['leaveRequestId']));

        $body = $this->commit($pair['b'], $pair['conversation'], $this->commitBody($pair['bDevice'], 3, leaveRequestId: $request['leaveRequestId']));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_epoch', $body['code']);
        self::assertSame(2, $body['currentEpoch']);
    }

    public function testCommitWithUnknownLeaveRequestRejected(): void
    {
        $pair = $this->pair();

        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1, leaveRequestId: Uuid::v4()->toRfc4122()));

        self::assertResponseStatusCodeSame(422);
    }

    public function testMessageResendWithSameClientIdIsIdempotent(): void
    {
        $pair = $this->pair();
        $id = Uuid::v4()->toRfc4122();

        $first = $this->send($pair['a'], $pair['conversation'], self::envelope(), $id);
        self::assertResponseStatusCodeSame(201);
        $again = $this->send($pair['a'], $pair['conversation'], self::envelope(), $id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first['@id'], $again['@id']);
        self::assertSame(1, $this->em()->getRepository(Message::class)->count([]));

        $replyId = Uuid::v4()->toRfc4122();
        $this->jsonClient($pair['b'])->request('POST', $first['@id'].'/replies', ['json' => ['text' => self::envelope(), 'id' => $replyId]]);
        self::assertResponseStatusCodeSame(201);
        $reply = $this->jsonClient($pair['b'])->request('POST', $first['@id'].'/replies', ['json' => ['text' => self::envelope(), 'id' => $replyId]])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('/api/v1/messages/'.$replyId, $reply['@id']);
        $this->em()->clear();
        self::assertSame(1, $this->em()->find(Message::class, basename($first['@id']))?->getReplyCount());
    }

    public function testClientIdReusedAcrossConversationsRejected(): void
    {
        $pair = $this->pair();
        $other = $this->conversationBetween([$pair['a'], $this->peerWithDevice($pair['a'])['user']], encrypted: true);
        $id = Uuid::v4()->toRfc4122();
        $this->send($pair['a'], $pair['conversation'], self::envelope(), $id);

        $this->send($pair['a'], $other, self::envelope(), $id);

        self::assertResponseStatusCodeSame(409);
    }

    public function testClientIdOfAnotherAuthorRejected(): void
    {
        $pair = $this->pair();
        $id = Uuid::v4()->toRfc4122();
        $root = $this->send($pair['a'], $pair['conversation'], self::envelope(), $id);

        $this->send($pair['b'], $pair['conversation'], self::envelope(), $id);
        self::assertResponseStatusCodeSame(409);

        $this->jsonClient($pair['a'])->request('POST', $root['@id'].'/replies', ['json' => ['text' => self::envelope(), 'id' => $id]]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testResendAfterGenerationFrozenReturnsOriginal(): void
    {
        $pair = $this->pair();
        $this->commit($pair['a'], $pair['conversation'], $this->commitBody($pair['aDevice'], 1));
        $rootId = Uuid::v4()->toRfc4122();
        $root = $this->send($pair['a'], $pair['conversation'], self::envelope(1), $rootId);
        $replyId = Uuid::v4()->toRfc4122();
        $this->jsonClient($pair['b'])->request('POST', $root['@id'].'/replies', ['json' => ['text' => self::envelope(1), 'id' => $replyId]]);
        self::assertResponseStatusCodeSame(201);
        $bReset = $this->resetIdentity($pair['b'], $pair['bKeys']);
        $this->commit($pair['b'], $pair['conversation'], $this->commitBody($bReset, 2, generation: 2));

        $this->send($pair['a'], $pair['conversation'], self::envelope(1), $rootId);
        self::assertResponseStatusCodeSame(200);

        $this->jsonClient($pair['b'])->request('POST', $root['@id'].'/replies', ['json' => ['text' => self::envelope(1), 'id' => $replyId]]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testFailedSendLeavesNothingForAResendToReturn(): void
    {
        $pair = $this->pair();
        $foreign = new MediaObject();
        $foreign->filePath = 'foreign.bin';
        $foreign->type = 'encrypted_attachment';
        $this->em()->persist($foreign);
        $this->em()->flush();
        $iri = static::getContainer()->get(IriConverterInterface::class)->getIriFromResource($foreign);
        $id = Uuid::v4()->toRfc4122();

        $this->jsonClient($pair['a'])->request('POST', '/api/v1/conversations/'.$pair['conversation']->getIdentifier().'/messages', [
            'json' => ['text' => self::envelope(), 'id' => $id, 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->em()->find(Message::class, $id));

        $this->send($pair['a'], $pair['conversation'], self::envelope(), $id);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array{a: User, b: User, aKeys: E2eeKeys, bKeys: E2eeKeys, aDevice: string, bDevice: string, bKeyPair: non-empty-string, conversation: Conversation}
     */
    private function pair(): array
    {
        [$a, $aKeys, $aClient] = $this->userWithAccount();
        $aDevice = $this->registerDevice($aClient, $aKeys, $a);
        [$b, $bKeys, $bClient] = $this->userWithAccount();
        $bDevice = $this->registerDevice($bClient, $bKeys, $b);
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);

        return [
            'a' => $a,
            'b' => $b,
            'aKeys' => $aKeys,
            'bKeys' => $bKeys,
            'aDevice' => $aDevice['body']['deviceId'],
            'bDevice' => $bDevice['body']['deviceId'],
            'bKeyPair' => $bDevice['keyPair'],
            'conversation' => $this->conversationBetween([$a, $b], encrypted: true),
        ];
    }

    /** @return array{user: User, deviceId: string} */
    private function peerWithDevice(User $of): array
    {
        $peer = $this->sharingPeer($of);
        $keys = new E2eeKeys();
        $this->plainJsonClient($peer)->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => $keys->accountPublicKey()]]);

        return ['user' => $peer, 'deviceId' => $this->registerDevice($this->plainJsonClient($peer), $keys, $peer)['body']['deviceId']];
    }

    private function resetIdentity(User $user, E2eeKeys $keys): string
    {
        $this->em()->createQuery('UPDATE '.E2eeAccount::class.' a SET a.generation = a.generation + 1 WHERE a.user = :user')
            ->setParameter('user', $user->getId())
            ->execute();
        $device = $keys->device((int) $user->getId(), generation: 2, name: 'Reset');
        $this->plainJsonClient($user)->request('POST', '/api/v1/e2ee/devices', ['json' => $device['body']]);
        self::assertResponseStatusCodeSame(201);

        return $device['body']['deviceId'];
    }

    /**
     * @param list<array{deviceId: string, data: string}> $welcomes
     *
     * @return array<string, mixed>
     */
    private function commitBody(string $deviceId, int $epoch, int $generation = 1, array $welcomes = [], ?string $leaveRequestId = null): array
    {
        return [
            'commitId' => Uuid::v4()->toRfc4122(),
            'deviceId' => $deviceId,
            'epoch' => $epoch,
            'generation' => $generation,
            'commit' => base64_encode('commit-'.$epoch),
            'groupInfo' => base64_encode('group-info-'.$epoch),
            'welcomes' => $welcomes,
            'leaveRequestId' => $leaveRequestId,
        ];
    }

    /** @return array{deviceId: string, data: string} */
    private function welcome(string $deviceId, string $data = 'welcome'): array
    {
        return ['deviceId' => $deviceId, 'data' => base64_encode($data)];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function commit(User $as, Conversation $conversation, array $body): array
    {
        return $this->plainJsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mls/commits', ['json' => $body])->toArray(false);
    }

    /** @return array<string, mixed> */
    private function state(User $as, Conversation $conversation): array
    {
        $state = $this->plainJsonClient($as)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier().'/mls/state')->toArray();
        self::assertResponseIsSuccessful();

        return $state;
    }

    /** @return list<array<string, mixed>> */
    private function commits(User $as, Conversation $conversation, int $after): array
    {
        $body = $this->plainJsonClient($as)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier().'/mls/commits?after='.$after)->toArray();
        self::assertResponseIsSuccessful();

        return $body['items'];
    }

    /** @return list<array<string, mixed>> */
    private function welcomes(User $as, ?string $deviceId = null, string $query = ''): array
    {
        $body = $this->plainJsonClient($as)->request('GET', '/api/v1/e2ee/welcomes'.(null === $deviceId ? $query : '?deviceId='.$deviceId))->toArray();
        self::assertResponseIsSuccessful();

        return $body['items'];
    }

    /** @return list<array<string, mixed>> */
    private function leaveRequests(User $as, Conversation $conversation): array
    {
        $body = $this->plainJsonClient($as)->request('GET', '/api/v1/e2ee/leave-requests?conversation='.$conversation->getIdentifier())->toArray();
        self::assertResponseIsSuccessful();

        return $body['items'];
    }

    /** @param array{leaveRequestId: string, requestedAt: int, signature: string} $request */
    private function leave(User $as, string $deviceId, array $request): void
    {
        $this->plainJsonClient($as)->request('POST', '/api/v1/e2ee/devices/'.$deviceId.'/leave', ['json' => $request]);
    }

    /** @param non-empty-string $signerKeyPair */
    private function revoke(User $as, string $deviceId, string $signerDeviceId, string $signerKeyPair): void
    {
        $this->plainJsonClient($as)->request('DELETE', '/api/v1/e2ee/devices/'.$deviceId, [
            'json' => ['revocation' => E2eeKeys::revocation($deviceId, $signerDeviceId, $signerKeyPair)],
        ]);
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function send(User $as, Conversation $conversation, string $text, ?string $id = null): array
    {
        return $this->jsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => null === $id ? ['text' => $text] : ['text' => $text, 'id' => $id],
        ])->toArray(false);
    }

    /** @return list<array<string, mixed>> */
    private function events(string $topic, string $type): array
    {
        $events = [];
        foreach (RecordingMercureHub::updates() as $update) {
            $data = json_decode($update->getData(), true);
            if (\in_array($topic, $update->getTopics(), true) && \is_array($data) && $type === ($data['type'] ?? null)) {
                $events[] = $data;
            }
        }

        return $events;
    }
}
