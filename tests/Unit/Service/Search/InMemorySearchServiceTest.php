<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use App\Dto\Search\SearchOptions;
use App\Tests\Stub\InMemorySearchService;
use PHPUnit\Framework\TestCase;

final class InMemorySearchServiceTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function doc(string $id, int $channelId, string $text, int $createdAt = 100): array
    {
        return [
            'id' => $id,
            'text' => $text,
            'authorId' => 1,
            'authorName' => 'Alice',
            'createdAt' => $createdAt,
            'channelId' => $channelId,
            'conversationId' => null,
            'pageNumber' => 1,
        ];
    }

    public function testSearchChannelsMatchesOnlyListedChannels(): void
    {
        $svc = new InMemorySearchService();
        $svc->injectDocument($this->doc('m1', 10, 'hello world'));
        $svc->injectDocument($this->doc('m2', 20, 'hello again'));
        $svc->injectDocument($this->doc('m3', 30, 'hello hidden'));

        $result = $svc->searchChannels([10, 20], 'hello');

        $ids = array_column($result['hits'], 'id');
        sort($ids);
        self::assertSame(['m1', 'm2'], $ids);
        self::assertSame(2, $result['total']);
    }

    public function testSearchChannelsEmptyIdListMatchesNothing(): void
    {
        $svc = new InMemorySearchService();
        $svc->injectDocument($this->doc('m1', 10, 'hello world'));

        $result = $svc->searchChannels([], 'hello');

        self::assertSame([], $result['hits']);
        self::assertSame(0, $result['total']);
    }

    public function testSearchChannelsHonoursAuthorFilter(): void
    {
        $svc = new InMemorySearchService();
        $svc->injectDocument($this->doc('m1', 10, 'hello world'));
        $other = $this->doc('m2', 10, 'hello other');
        $other['authorId'] = 2;
        $svc->injectDocument($other);

        $result = $svc->searchChannels([10], 'hello', new SearchOptions(authorId: 2));

        self::assertSame(['m2'], array_column($result['hits'], 'id'));
    }
}
