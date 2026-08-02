<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Message\MessageKind;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ReportTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @return array{community: Community, channel: Channel, author: User, message: Message}
     */
    private function seedChannelMessage(string $identifier, MessageKind $kind = MessageKind::Standard): array
    {
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        $channel = ChannelFactory::new()->inCommunity($community)->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->withText('offensive text')->with(['kind' => $kind])->create();

        return [
            'community' => $community,
            'channel' => $channel,
            'author' => $author,
            'message' => $message,
        ];
    }

    public function testMemberReportsMessage(): void
    {
        $seed = $this->seedChannelMessage('rep-msg');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);

        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'harassment',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('harassment', $data['category']);
        self::assertSame('open', $data['status']);
        self::assertSame('offensive text', $data['messageTextSnapshot']);
        self::assertSame('rep-msg', $data['communityIdentifier']);
    }

    public function testCannotReportOwnMessage(): void
    {
        $seed = $this->seedChannelMessage('rep-own');

        $this->jsonClient($seed['author'])->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'spam',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCannotReportSystemMessage(): void
    {
        $seed = $this->seedChannelMessage('rep-sys', MessageKind::System);
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);

        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'spam',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testDuplicateReportRejected(): void
    {
        $seed = $this->seedChannelMessage('rep-dup');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);

        $payload = ['json' => ['messageId' => $seed['message']->getId(), 'category' => 'spam']];
        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', $payload);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOtherCategoryRequiresComment(): void
    {
        $seed = $this->seedChannelMessage('rep-other');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);

        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'other',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCommunityModListsAndResolvesReport(): void
    {
        $seed = $this->seedChannelMessage('rep-resolve');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createModeratorForCommunity($mod, $seed['community']);

        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'harassment',
        ]]);
        $reportId = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $list = $this->jsonClient($mod)->request('GET', '/api/v1/communities/rep-resolve/reports')->toArray();
        self::assertCount(1, $list['member'] ?? $list['hydra:member']);

        $this->jsonClient($mod)->request('PATCH', '/api/v1/reports/'.$reportId, [
            'json' => ['status' => 'resolved', 'resolutionNote' => 'Handled'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('resolved', $data['status']);
    }

    public function testNonModMemberCannotListReports(): void
    {
        $seed = $this->seedChannelMessage('rep-noperm');
        $member = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($member, $seed['community']);

        $this->jsonClient($member)->request('GET', '/api/v1/communities/rep-noperm/reports');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEscalatedReportClosableOnlyByAdmin(): void
    {
        $seed = $this->seedChannelMessage('rep-esc');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createModeratorForCommunity($mod, $seed['community']);

        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'illegal_content',
        ]]);
        $reportId = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->jsonClient($mod)->request('PATCH', '/api/v1/reports/'.$reportId, [
            'json' => ['status' => 'escalated'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->jsonClient($mod)->request('PATCH', '/api/v1/reports/'.$reportId, [
            'json' => ['status' => 'resolved'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $admin = UserFactory::new()->admin()->create();
        $this->jsonClient($admin)->request('PATCH', '/api/v1/reports/'.$reportId, [
            'json' => ['status' => 'resolved'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testAdminListsAllReports(): void
    {
        $seed = $this->seedChannelMessage('rep-admin');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);
        $this->jsonClient($reporter)->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'spam',
        ]]);

        $admin = UserFactory::new()->admin()->create();
        $list = $this->jsonClient($admin)->request('GET', '/api/v1/admin/reports')->toArray();
        self::assertGreaterThanOrEqual(1, \count($list['member'] ?? $list['hydra:member']));
    }
}
