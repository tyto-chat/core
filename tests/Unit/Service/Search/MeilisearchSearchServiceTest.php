<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use App\Entity\Message;
use App\Service\Search\MeilisearchSearchService;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MeilisearchSearchServiceTest extends TestCase
{
    public function testIndexMessageConfiguresIndexOnceProcessWide(): void
    {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSearchableAttributes');
        $index->expects($this->once())->method('updateFilterableAttributes')
            ->with(['channelId', 'conversationId', 'authorId', 'createdAt']);
        $index->expects($this->once())->method('updateSortableAttributes');
        $index->expects($this->exactly(2))->method('addDocuments');

        $client = $this->createMock(Client::class);
        $client->expects($this->atLeastOnce())->method('index')
            ->with(MeilisearchSearchService::INDEX_MESSAGES)->willReturn($index);
        $client->method('getIndex')->willReturn($index);

        $service = new MeilisearchSearchService($client, new NullLogger());
        $service->indexMessage(new Message());
        $service->indexMessage(new Message());
    }

    public function testEnsureIndexConfiguredIsMemoized(): void
    {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSearchableAttributes');

        $client = self::createStub(Client::class);
        $client->method('index')->willReturn($index);
        $client->method('getIndex')->willReturn($index);

        $service = new MeilisearchSearchService($client, new NullLogger());
        $service->ensureIndexConfigured();
        $service->ensureIndexConfigured();
    }

    public function testFailedConfigurationIsRetriedOnNextWrite(): void
    {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->exactly(2))->method('updateSearchableAttributes')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('meili down')),
                [],
            );

        $client = self::createStub(Client::class);
        $client->method('index')->willReturn($index);
        $client->method('getIndex')->willReturn($index);

        $service = new MeilisearchSearchService($client, new NullLogger());

        try {
            $service->ensureIndexConfigured();
            self::fail('expected throw');
        } catch (\RuntimeException) {
        }

        $service->ensureIndexConfigured();
    }
}
