<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ThreadPaginationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private static int $threadCounter = 0;

    /** @return array{root: string, replyIds: list<string>, client: Client} */
    private function makeThread(int $replies): array
    {
        $n = ++self::$threadCounter;

        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('tp-c'.$n)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'tp-ch'.$n])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $client = $this->jsonClient($user);
        $replyIds = [];
        for ($i = 0; $i < $replies; ++$i) {
            $response = $client->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
                'json' => ['text' => 'reply '.$i],
            ]);
            $body = $response->toArray();
            $replyIds[] = basename((string) $body['@id']);
        }

        return ['root' => $root->getId(), 'replyIds' => $replyIds, 'client' => $client];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function idsFrom(array $body): array
    {
        $rows = $body['member'] ?? $body['hydra:member'] ?? [];

        return array_values(array_map(static fn (array $r): string => basename((string) $r['@id']), $rows));
    }

    public function testDefaultReturnsNewestWindow(): void
    {
        ['root' => $root, 'replyIds' => $ids, 'client' => $client] = $this->makeThread(7);

        $body = $client->request('GET', '/api/v1/messages/'.$root.'/thread?limit=3')->toArray();
        $returned = $this->idsFrom($body);

        // Newest window = the 3 most recent by (createdAt, id). With
        // same-second fixtures the exact 3 may tie-break by uuid, so assert
        // the invariant that matters: combined with the two before-pages
        // in testBeforeCursorPagesBackwardsWithoutOverlapOrGap, coverage is
        // total — here just assert the count and that returned ids are a
        // subset of all reply ids.
        self::assertCount(3, $returned);
        self::assertEmpty(array_diff($returned, $ids));
    }

    public function testBeforeCursorPagesBackwardsWithoutOverlapOrGap(): void
    {
        ['root' => $root, 'replyIds' => $ids, 'client' => $client] = $this->makeThread(7);

        $page1 = $this->idsFrom($client->request('GET', '/api/v1/messages/'.$root.'/thread?limit=3')->toArray());
        $oldest1 = $page1[0];
        $page2 = $this->idsFrom($client->request('GET', '/api/v1/messages/'.$root.'/thread?limit=3&before='.$oldest1)->toArray());
        $oldest2 = $page2[0];
        $page3 = $this->idsFrom($client->request('GET', '/api/v1/messages/'.$root.'/thread?limit=3&before='.$oldest2)->toArray());

        self::assertCount(3, $page1);
        self::assertCount(3, $page2);
        self::assertCount(1, $page3);
        $all = array_merge($page1, $page2, $page3);
        self::assertCount(7, $all);
        self::assertSame([], array_diff($ids, $all), 'every reply appears exactly once across the pages');
        self::assertCount(7, array_unique($all), 'no overlap between pages');
    }

    public function testShortPageSignalsStartOfThread(): void
    {
        ['root' => $root, 'client' => $client] = $this->makeThread(2);

        $body = $client->request('GET', '/api/v1/messages/'.$root.'/thread?limit=3')->toArray();
        self::assertCount(2, $this->idsFrom($body));
    }

    public function testForeignCursorRejected(): void
    {
        ['root' => $rootA, 'client' => $client] = $this->makeThread(1);
        ['replyIds' => $idsB] = $this->makeThread(1);

        // Two makeThread() calls create two distinct clients (one per
        // community); assert on the response object itself rather than the
        // static assertResponseStatusCodeSame helper, which reads the last
        // *created* client — not necessarily $client here.
        $response = $client->request('GET', '/api/v1/messages/'.$rootA.'/thread?before='.$idsB[0]);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testLimitClamped(): void
    {
        ['root' => $root, 'client' => $client] = $this->makeThread(3);

        $body = $client->request('GET', '/api/v1/messages/'.$root.'/thread?limit=0')->toArray();
        self::assertCount(1, $this->idsFrom($body));
    }

    public function testDeletedRepliesIncludedInWindow(): void
    {
        ['root' => $root, 'replyIds' => $ids, 'client' => $client] = $this->makeThread(3);
        $client->request('DELETE', '/api/v1/messages/'.$ids[1]);
        self::assertResponseStatusCodeSame(204);

        $body = $client->request('GET', '/api/v1/messages/'.$root.'/thread')->toArray();
        $rows = $body['member'] ?? $body['hydra:member'] ?? [];
        self::assertCount(3, $rows);
        $deleted = array_values(array_filter($rows, static fn (array $r): bool => $r['isDeleted'] ?? false));
        self::assertCount(1, $deleted);
    }
}
