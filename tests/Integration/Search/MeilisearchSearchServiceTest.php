<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Dto\Search\SearchOptions;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Message\MessageKind;
use App\Service\Search\MeilisearchSearchService;
use Doctrine\Common\Collections\ArrayCollection;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Real Meilisearch (ddev sidecar / CI service). Unit tests only assert the
 * SDK was called with certain arguments; everything here is engine behaviour
 * those tests cannot see — filterable attributes actually permitting the
 * channelId filter the search security boundary rides on, highlight tags,
 * epoch sort, filter-scoped deletes, shortcode tokenisation.
 *
 * All documents use the shared 'messages' index (the service hardcodes it),
 * so every test doc carries unique 993xxx container/author ids, every search
 * filters on them, and tearDown deletes the docs — a dev index next door is
 * never touched beyond idempotent settings updates.
 */
#[AllowMockObjectsWithoutExpectations]
class MeilisearchSearchServiceTest extends TestCase
{
    private const CHANNEL_A = 993001;
    private const CHANNEL_B = 993002;
    private const CHANNEL_C = 993003;
    private const CONVERSATION = 993050;
    private const AUTHOR_A = 993101;
    private const AUTHOR_B = 993102;

    private Client $client;
    private MeilisearchSearchService $service;

    /** @var list<string> */
    private array $docIds = [];

    #[\Override]
    protected function setUp(): void
    {
        $url = $_ENV['MEILI_URL'] ?? $_SERVER['MEILI_URL'] ?? null;
        $key = $_ENV['MEILI_MASTER_KEY'] ?? $_SERVER['MEILI_MASTER_KEY'] ?? null;
        self::assertIsString($url, 'MEILI_URL must be set for integration tests.');
        self::assertIsString($key, 'MEILI_MASTER_KEY must be set for integration tests.');

        $this->client = new Client($url, $key);

        try {
            $this->client->health();
        } catch (\Throwable $e) {
            self::fail('Meilisearch is not reachable at '.$url.': '.$e->getMessage());
        }

        $this->service = new MeilisearchSearchService($this->client, new NullLogger());
        $this->service->ensureIndexConfigured();
        $this->waitForIdle();
        $this->docIds = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ([] !== $this->docIds) {
            $this->client->index(MeilisearchSearchService::INDEX_MESSAGES)->deleteDocuments($this->docIds);
            $this->waitForIdle();
        }
    }

    private function waitForIdle(): void
    {
        $query = new TasksQuery()->setStatuses(['enqueued', 'processing']);
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            if ([] === $this->client->getTasks($query)->getResults()) {
                return;
            }
            usleep(50_000);
        }
        self::fail('Meilisearch did not become idle within 10s.');
    }

    /**
     * @param array{
     *     text?: string,
     *     channelId?: ?int,
     *     conversationId?: ?int,
     *     authorId?: int,
     *     createdAt?: int,
     *     deleted?: bool,
     *     kind?: MessageKind,
     * } $overrides
     */
    private function buildMessage(string $id, array $overrides = []): Message
    {
        $this->docIds[] = $id;

        $profile = $this->createMock(Profile::class);
        $profile->method('getName')->willReturn('Author '.($overrides['authorId'] ?? self::AUTHOR_A));

        $author = $this->createMock(User::class);
        $author->method('getId')->willReturn($overrides['authorId'] ?? self::AUTHOR_A);
        $author->method('getProfile')->willReturn($profile);

        $channelId = \array_key_exists('channelId', $overrides) ? $overrides['channelId'] : self::CHANNEL_A;
        $channel = null;
        if (null !== $channelId) {
            $channel = $this->createMock(Channel::class);
            $channel->method('getId')->willReturn($channelId);
        }

        $conversationId = $overrides['conversationId'] ?? null;
        $conversation = null;
        if (null !== $conversationId) {
            $conversation = $this->createMock(Conversation::class);
            $conversation->method('getId')->willReturn($conversationId);
        }

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn($id);
        $message->method('getText')->willReturn($overrides['text'] ?? 'hello world');
        $message->method('getRevisions')->willReturn(new ArrayCollection());
        $message->method('getCreatedBy')->willReturn($author);
        $message->method('getCreatedAt')->willReturn(
            new \DateTimeImmutable('@'.($overrides['createdAt'] ?? 1_760_000_000)),
        );
        $message->method('getChannel')->willReturn($channel);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getPageNumber')->willReturn(7);
        $message->method('isDeleted')->willReturn($overrides['deleted'] ?? false);
        $message->method('getKind')->willReturn($overrides['kind'] ?? MessageKind::Standard);

        return $message;
    }

    private static function uuid(string $suffix): string
    {
        return '99300000-0000-4000-8000-'.str_pad($suffix, 12, '0', STR_PAD_LEFT);
    }

    private function indexAndWait(Message ...$messages): void
    {
        $this->service->indexMessages($messages);
        $this->waitForIdle();
    }

    public function testIndexedMessageIsFoundInItsChannelWithFullDocumentShape(): void
    {
        $this->indexAndWait($this->buildMessage(self::uuid('1'), ['text' => 'grumpy owl facts']));

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);

        $result = $this->service->searchChannel($channel, 'grumpy');

        self::assertSame(1, $result['total']);
        $hit = $result['hits'][0];
        self::assertSame(self::uuid('1'), $hit['id']);
        self::assertSame('grumpy owl facts', $hit['text']);
        self::assertSame(self::AUTHOR_A, $hit['authorId']);
        self::assertSame('Author '.self::AUTHOR_A, $hit['authorName']);
        self::assertSame(self::CHANNEL_A, $hit['channelId']);
        self::assertNull($hit['conversationId']);
        self::assertSame(7, $hit['pageNumber']);
    }

    public function testHitsCarryMarkHighlightedSnippets(): void
    {
        $this->indexAndWait($this->buildMessage(self::uuid('2'), ['text' => 'the nocturnal hunter strikes']));

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);

        $result = $this->service->searchChannel($channel, 'nocturnal');

        $formatted = $result['hits'][0]['_formatted'] ?? null;
        self::assertIsArray($formatted);
        self::assertStringContainsString('<mark>nocturnal</mark>', (string) $formatted['text']);
    }

    public function testChannelSearchDoesNotLeakOtherChannels(): void
    {
        $this->indexAndWait(
            $this->buildMessage(self::uuid('3'), ['text' => 'secret parliament', 'channelId' => self::CHANNEL_A]),
            $this->buildMessage(self::uuid('4'), ['text' => 'secret parliament', 'channelId' => self::CHANNEL_B]),
        );

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);

        $result = $this->service->searchChannel($channel, 'parliament');

        self::assertSame(1, $result['total']);
        self::assertSame(self::uuid('3'), $result['hits'][0]['id']);
    }

    public function testCommunitySearchHonoursTheVisibleChannelSet(): void
    {
        $this->indexAndWait(
            $this->buildMessage(self::uuid('5'), ['text' => 'talon maintenance', 'channelId' => self::CHANNEL_A]),
            $this->buildMessage(self::uuid('6'), ['text' => 'talon maintenance', 'channelId' => self::CHANNEL_B]),
            $this->buildMessage(self::uuid('7'), ['text' => 'talon maintenance', 'channelId' => self::CHANNEL_C]),
        );

        $result = $this->service->searchChannels([self::CHANNEL_A, self::CHANNEL_B], 'talon');

        $ids = array_column($result['hits'], 'id');
        sort($ids);
        self::assertSame([self::uuid('5'), self::uuid('6')], $ids);
    }

    public function testEmptyVisibleChannelSetShortCircuitsToNoHits(): void
    {
        $result = $this->service->searchChannels([], 'anything');

        self::assertSame(['hits' => [], 'total' => 0, 'limit' => 25, 'offset' => 0], $result);
    }

    public function testConversationSearchIsScopedToTheConversation(): void
    {
        $this->indexAndWait(
            $this->buildMessage(self::uuid('8'), [
                'text' => 'midnight whisper',
                'channelId' => null,
                'conversationId' => self::CONVERSATION,
            ]),
            $this->buildMessage(self::uuid('9'), ['text' => 'midnight whisper', 'channelId' => self::CHANNEL_A]),
        );

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(self::CONVERSATION);

        $result = $this->service->searchConversation($conversation, 'whisper');

        self::assertSame(1, $result['total']);
        self::assertSame(self::uuid('8'), $result['hits'][0]['id']);
    }

    public function testAuthorAndDateOptionsNarrowResults(): void
    {
        $this->indexAndWait(
            $this->buildMessage(self::uuid('10'), ['text' => 'moulting season', 'authorId' => self::AUTHOR_A, 'createdAt' => 1_700_000_000]),
            $this->buildMessage(self::uuid('11'), ['text' => 'moulting season', 'authorId' => self::AUTHOR_B, 'createdAt' => 1_710_000_000]),
        );

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);

        $byAuthor = $this->service->searchChannel($channel, 'moulting', new SearchOptions(authorId: self::AUTHOR_B));
        self::assertSame([self::uuid('11')], array_column($byAuthor['hits'], 'id'));

        $before = $this->service->searchChannel($channel, 'moulting', new SearchOptions(createdBefore: 1_705_000_000));
        self::assertSame([self::uuid('10')], array_column($before['hits'], 'id'));

        $after = $this->service->searchChannel($channel, 'moulting', new SearchOptions(createdAfter: 1_705_000_000));
        self::assertSame([self::uuid('11')], array_column($after['hits'], 'id'));
    }

    public function testHitsAreSortedNewestFirst(): void
    {
        $this->indexAndWait(
            $this->buildMessage(self::uuid('12'), ['text' => 'feather report old', 'createdAt' => 1_700_000_000]),
            $this->buildMessage(self::uuid('13'), ['text' => 'feather report new', 'createdAt' => 1_720_000_000]),
        );

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);

        $result = $this->service->searchChannel($channel, 'feather');

        self::assertSame([self::uuid('13'), self::uuid('12')], array_column($result['hits'], 'id'));
    }

    public function testLimitAndOffsetPaginate(): void
    {
        $this->indexAndWait(
            $this->buildMessage(self::uuid('14'), ['text' => 'pellet analysis', 'createdAt' => 1_700_000_000]),
            $this->buildMessage(self::uuid('15'), ['text' => 'pellet analysis', 'createdAt' => 1_710_000_000]),
            $this->buildMessage(self::uuid('16'), ['text' => 'pellet analysis', 'createdAt' => 1_720_000_000]),
        );

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);

        $page = $this->service->searchChannel($channel, 'pellet', new SearchOptions(limit: 2, offset: 2));

        self::assertSame(3, $page['total']);
        self::assertSame([self::uuid('14')], array_column($page['hits'], 'id'));
        self::assertSame(2, $page['limit']);
        self::assertSame(2, $page['offset']);
    }

    public function testCustomEmojiShortcodeIsSearchableAsText(): void
    {
        $this->indexAndWait($this->buildMessage(self::uuid('17'), [
            'text' => '<p>look <img src="/x.gif" data-shortcode="party_owl" alt=""> here</p>',
        ]));

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);

        $result = $this->service->searchChannel($channel, 'party_owl');

        self::assertSame(1, $result['total']);
        self::assertSame('look party_owl here', $result['hits'][0]['text']);
    }

    public function testReindexingADeletedMessageRemovesItsDocument(): void
    {
        $id = self::uuid('18');
        $this->indexAndWait($this->buildMessage($id, ['text' => 'soon to vanish']));

        $this->service->indexMessage($this->buildMessage($id, ['text' => 'soon to vanish', 'deleted' => true]));
        $this->waitForIdle();

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);
        self::assertSame(0, $this->service->searchChannel($channel, 'vanish')['total']);
    }

    public function testSystemMessagesAreNeverIndexed(): void
    {
        $this->indexAndWait($this->buildMessage(self::uuid('19'), [
            'text' => 'welcome aboard system',
            'kind' => MessageKind::System,
        ]));

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);
        self::assertSame(0, $this->service->searchChannel($channel, 'aboard')['total']);
    }

    public function testBatchIndexingMixesAddsAndRemovals(): void
    {
        $keepId = self::uuid('20');
        $dropId = self::uuid('21');
        $this->indexAndWait($this->buildMessage($dropId, ['text' => 'mixed batch drop']));

        $this->indexAndWait(
            $this->buildMessage($keepId, ['text' => 'mixed batch keep']),
            $this->buildMessage($dropId, ['text' => 'mixed batch drop', 'deleted' => true]),
        );

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);
        $result = $this->service->searchChannel($channel, 'batch');

        self::assertSame([$keepId], array_column($result['hits'], 'id'));
    }

    public function testRemoveMessageDeletesTheDocument(): void
    {
        $id = self::uuid('22');
        $this->indexAndWait($this->buildMessage($id, ['text' => 'explicit removal target']));

        $this->service->removeMessage($id);
        $this->waitForIdle();

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_A);
        self::assertSame(0, $this->service->searchChannel($channel, 'removal')['total']);
    }

    public function testRemoveChannelDocumentsOnlyTouchesThatChannel(): void
    {
        $this->indexAndWait(
            $this->buildMessage(self::uuid('23'), ['text' => 'channel purge probe', 'channelId' => self::CHANNEL_A]),
            $this->buildMessage(self::uuid('24'), ['text' => 'channel purge probe', 'channelId' => self::CHANNEL_B]),
        );

        $this->service->removeChannelDocuments(self::CHANNEL_A);
        $this->waitForIdle();

        $result = $this->service->searchChannels([self::CHANNEL_A, self::CHANNEL_B], 'purge');

        self::assertSame([self::uuid('24')], array_column($result['hits'], 'id'));
    }
}
