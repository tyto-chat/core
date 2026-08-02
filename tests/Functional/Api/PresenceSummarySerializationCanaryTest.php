<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Service\Presence\PresenceServiceInterface;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemoryPresenceService;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The HTTP cache layer caches `/communities/{id}/presence/summary` responses
 * per-bucket instead of per-viewer (see HTTP_CACHE_LAYER_PLAN.md risk #2,
 * `tyto_http_cache: 'presence'`). That is only safe if the serialized bytes
 * are viewer-agnostic: `CommunityPresenceSummaryDto` carries only a single
 * `onlineCount` int computed from community membership state, with nothing
 * caller-specific — but this test freezes that fact rather than assuming it.
 *
 * This test freezes the exact field set of the serialized payload (the
 * `onlineCount` field plus whatever JSON-LD envelope keys API Platform emits
 * for a non-collection DTO output). If it fails, a field was added to (or
 * removed from) the DTO or its serialization group: before updating
 * CERTIFIED_FIELDS, prove any new field is viewer-agnostic, or it will
 * silently leak one viewer's data into another viewer's cached bucket.
 */
class PresenceSummarySerializationCanaryTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @var list<string>
     */
    private const array CERTIFIED_FIELDS = [
        '@context',
        '@id',
        '@type',
        'guestsOnline',
        'onlineCount',
    ];

    private function presenceStub(): InMemoryPresenceService
    {
        $svc = static::getContainer()->get(PresenceServiceInterface::class);
        \assert($svc instanceof InMemoryPresenceService);

        return $svc;
    }

    public function testPresenceSummaryFieldSetMatchesCertifiedList(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('canary-pres-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $response = $this->jsonClient($member)->request('GET', '/api/v1/communities/canary-pres-c/presence/summary');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();

        $actualFields = array_keys($data);
        sort($actualFields);
        $certified = self::CERTIFIED_FIELDS;
        sort($certified);

        self::assertSame(
            $certified,
            $actualFields,
            'presence:read summary field set changed — see class docblock before updating CERTIFIED_FIELDS.',
        );
    }

    /**
     * The real cache-safety guard: a cache-flagged summary is stored once per
     * bucket and replayed to every viewer in it, so the serialized bytes MUST
     * NOT depend on WHO fetched them. This fetches the same community summary
     * as two different plain members and asserts byte-identical responses.
     *
     * Both viewers are pre-touched online (alongside the third member)
     * before either request: `PresenceTrackingListener` piggybacks a
     * liveness touch on every authed request, so an untouched viewer would
     * flip themselves online mid-test and change the count between the two
     * fetches — a real side effect of the request itself, not of the cached
     * payload. Touching everyone upfront isolates what this test is actually
     * checking: that the SAME underlying state serializes identically
     * regardless of who reads it.
     */
    public function testPresenceSummaryIsByteIdenticalAcrossViewers(): void
    {
        $memberA = UserFactory::createOne();
        $memberB = UserFactory::createOne();
        $onlineMember = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('canary-pres-xv-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($memberA, $community);
        CommunityMemberFactory::createForUserAndCommunity($memberB, $community);
        CommunityMemberFactory::createForUserAndCommunity($onlineMember, $community);

        $this->presenceStub()->touch($memberA);
        $this->presenceStub()->touch($memberB);
        $this->presenceStub()->touch($onlineMember);

        $path = '/api/v1/communities/canary-pres-xv-c/presence/summary';

        $bodies = [];
        foreach (['a' => $memberA, 'b' => $memberB] as $label => $viewer) {
            $response = $this->jsonClient($viewer)->request('GET', $path);
            self::assertResponseIsSuccessful();
            $body = $response->toArray();
            // '@id' is a random JSON-LD blank-node genid assigned per
            // serialization (this DTO has no identifier property) — it
            // differs on every response regardless of viewer and is not
            // part of the cache-safety question this test is asking.
            unset($body['@id']);
            $bodies[$label] = json_encode($body, \JSON_THROW_ON_ERROR);
        }

        self::assertSame(
            $bodies['a'],
            $bodies['b'],
            'A cache-flagged presence summary must serialize identically for every viewer sharing the bucket.',
        );
    }
}
