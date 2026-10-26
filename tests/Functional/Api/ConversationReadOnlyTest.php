<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\CommunityMember;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ConversationReadOnlyTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private const MESSAGE = "You can't send messages in this conversation.";

    /** @return array{0: list<User>, 1: Conversation} */
    private function conversationOf(int $count): array
    {
        $community = CommunityFactory::createOne();
        $users = [];
        for ($i = 0; $i < $count; ++$i) {
            $user = UserFactory::createOne();
            CommunityMemberFactory::createForUserAndCommunity($user, $community);
            $users[] = $user;
        }
        $conversation = ConversationFactory::new()->withParticipants($users)->create();
        foreach ($users as $user) {
            ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        }

        return [$users, $conversation];
    }

    private function block(User $blocker, User $target): void
    {
        $this->plainJsonClient($blocker)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $target->getId()]]);
        self::assertResponseStatusCodeSame(201);
    }

    private function unblock(User $blocker, User $target): void
    {
        $this->plainJsonClient($blocker)->request('DELETE', '/api/v1/me/blocks/'.$target->getId());
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function send(User $as, Conversation $conversation, string $text = 'hi'): array
    {
        return $this->jsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => $text],
        ])->toArray(false);
    }

    /** @return array<string, mixed> */
    private function detail(User $as, Conversation $conversation): array
    {
        $body = $this->jsonClient($as)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier())->toArray();
        self::assertResponseIsSuccessful();

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function assertReadOnlyError(array $body): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::MESSAGE, $body['error']);
    }

    private function uploadFile(): UploadedFile
    {
        $path = sys_get_temp_dir().'/dm-ro-'.uniqid().'.txt';
        file_put_contents($path, 'hello');

        return new UploadedFile($path, 'note.txt', 'text/plain', null, true);
    }

    public function testDetailShowsBlockedForBlockerAndUnavailableForBlocked(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);

        $forA = $this->detail($a, $conversation);
        self::assertTrue($forA['readOnly']);
        self::assertSame('blocked', $forA['readOnlyReason']);

        $forB = $this->detail($b, $conversation);
        self::assertTrue($forB['readOnly']);
        self::assertSame('unavailable', $forB['readOnlyReason']);
    }

    public function testDetailWithoutBlockIsWritable(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);

        $body = $this->detail($a, $conversation);

        self::assertFalse($body['readOnly']);
        self::assertNull($body['readOnlyReason'] ?? null);
    }

    public function testListCarriesReadOnlyPerCaller(): void
    {
        [[$a, $b, $c], $group] = $this->conversationOf(3);
        $dmAB = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $dmAB);
        ConversationMemberFactory::createForUserAndConversation($b, $dmAB);
        $dmAC = ConversationFactory::new()->withParticipants([$a, $c])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $dmAC);
        ConversationMemberFactory::createForUserAndConversation($c, $dmAC);
        foreach ([$group, $dmAB, $dmAC] as $conversation) {
            $this->send($a, $conversation);
            self::assertResponseStatusCodeSame(201);
        }
        $this->block($a, $b);

        $byIdentifier = static fn (array $list): array => array_column($list['hydra:member'], null, 'identifier');

        $forA = $byIdentifier($this->jsonClient($a)->request('GET', '/api/v1/conversations')->toArray());
        self::assertTrue($forA[$dmAB->getIdentifier()]['readOnly']);
        self::assertSame('blocked', $forA[$dmAB->getIdentifier()]['readOnlyReason']);
        self::assertFalse($forA[$dmAC->getIdentifier()]['readOnly']);
        self::assertFalse($forA[$group->getIdentifier()]['readOnly']);

        $forB = $byIdentifier($this->jsonClient($b)->request('GET', '/api/v1/conversations')->toArray());
        self::assertTrue($forB[$dmAB->getIdentifier()]['readOnly']);
        self::assertSame('unavailable', $forB[$dmAB->getIdentifier()]['readOnlyReason']);
        self::assertFalse($forB[$group->getIdentifier()]['readOnly']);
    }

    public function testCreateOrFindResponseCarriesReadOnly(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);

        $body = $this->jsonClient($b)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => [$a->getId()]],
        ])->toArray();

        self::assertSame($conversation->getIdentifier(), $body['identifier']);
        self::assertTrue($body['readOnly']);
        self::assertSame('unavailable', $body['readOnlyReason']);
    }

    public function testSendRejectedBothDirections(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);

        $this->assertReadOnlyError($this->send($a, $conversation));
        $this->assertReadOnlyError($this->send($b, $conversation));
    }

    public function testReplyRejected(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $root = $this->send($a, $conversation);
        $this->block($a, $b);

        $body = $this->jsonClient($b)->request('POST', $root['@id'].'/replies', ['json' => ['text' => 'reply']])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testEditRejected(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $created = $this->send($a, $conversation);
        $this->block($a, $b);

        $body = $this->jsonClient($a)->request('PATCH', $created['@id'], [
            'json' => ['text' => 'edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testReactionAddRejected(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $created = $this->send($a, $conversation);
        $this->block($a, $b);

        $body = $this->jsonClient($b)->request('POST', $created['@id'].'/reactions', ['json' => ['emoji' => '👍']])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testOwnReactionRemovalAllowed(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $created = $this->send($a, $conversation);
        $reaction = $this->jsonClient($b)->request('POST', $created['@id'].'/reactions', ['json' => ['emoji' => '👍']])->toArray();
        $this->block($a, $b);

        $this->jsonClient($b)->request('DELETE', $reaction['@id']);

        self::assertResponseStatusCodeSame(204);
    }

    public function testAttachmentUploadRejected(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);

        $body = $this->uploadClient($b)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments', [
            'extra' => ['files' => ['file' => $this->uploadFile()]],
        ])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testTypingPingRejected(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);

        $body = $this->plainJsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/typing')->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testOwnMessageDeleteAllowed(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $created = $this->send($b, $conversation);
        $this->block($a, $b);

        $this->jsonClient($b)->request('DELETE', $created['@id']);

        self::assertResponseStatusCodeSame(204);
    }

    public function testHistoryStillReadable(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->send($a, $conversation, 'before the block');
        $this->block($a, $b);

        $page = $this->jsonClient($b)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages/current')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['before the block'], array_column($page['messages'], 'text'));
    }

    public function testUnblockRestoresWriting(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);
        $this->unblock($a, $b);

        $this->send($b, $conversation);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->detail($a, $conversation)['readOnly']);
    }

    public function testMutualBlockKeepsBlockedReasonForBoth(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);
        $this->block($b, $a);

        self::assertSame('blocked', $this->detail($a, $conversation)['readOnlyReason']);
        self::assertSame('blocked', $this->detail($b, $conversation)['readOnlyReason']);
    }

    public function testUnblockByOneSideOfMutualBlockKeepsReadOnly(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);
        $this->block($b, $a);
        $this->unblock($a, $b);

        $forA = $this->detail($a, $conversation);
        self::assertTrue($forA['readOnly']);
        self::assertSame('unavailable', $forA['readOnlyReason']);
        $forB = $this->detail($b, $conversation);
        self::assertTrue($forB['readOnly']);
        self::assertSame('blocked', $forB['readOnlyReason']);
        $this->assertReadOnlyError($this->send($a, $conversation));
    }

    public function testReadOnlyLiftedWhileTargetIsModerator(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($a, $b);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $membership = $em->getRepository(CommunityMember::class)->findOneBy(['user' => $b->getId()]);
        self::assertNotNull($membership);
        $membership->setRole(CommunityRole::Moderator);
        $em->flush();

        self::assertFalse($this->detail($a, $conversation)['readOnly']);
        $this->send($b, $conversation);
        self::assertResponseStatusCodeSame(201);

        $membership = $em->getRepository(CommunityMember::class)->findOneBy(['user' => $b->getId()]);
        self::assertNotNull($membership);
        $membership->setRole(CommunityRole::Member);
        $em->flush();

        self::assertTrue($this->detail($a, $conversation)['readOnly']);
    }

    public function testGroupConversationStaysWritableWithBlockInside(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(3);
        $this->block($a, $b);

        $this->send($b, $conversation);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->detail($b, $conversation)['readOnly']);
        self::assertFalse($this->detail($a, $conversation)['readOnly']);
    }
}
