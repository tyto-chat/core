<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Channel\ChannelRole;
use App\Enum\Community\CommunityRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The HTTP cache layer will cache `GET /api/communities/{identifier}`
 * per-bucket instead of per-viewer (see the community-detail-cache v4 plan).
 * That is only safe if every serialized byte is viewer-agnostic (same value
 * for every caller who lands in the same bucket) — the three fields this
 * normalizer used to inject (`isMember`, `currentUserHasMembership`,
 * `currentUserRole`) were exactly the opposite: per-viewer state riding a
 * payload that must be shareable. They were extracted to the personal
 * `/api/communities/{identifier}/membership` + `/api/me/community-memberships`
 * endpoints (task 1 of this batch) and removed from this payload entirely.
 *
 * `CommunityNormalizer`'s channel FILTERING (private channels hidden from
 * non-members) and `voiceEnabled` filtering are NOT per-viewer state in the
 * unsafe sense — they are what makes the payload bucket-correct in the first
 * place (a "standard" bucket viewer, member or not, always sees exactly the
 * public channel set; a private-channel grant-holder must NEVER share the
 * standard bucket, which is what the fingerprint probe below documents). Both
 * must keep working after the strip.
 *
 * The community `logo` field embeds signed `sign()` URLs (per-request HMAC
 * tokens with a `time() + ttl` expiry). They stay valid inside a cached
 * payload because the page-TTL clamp (`min(ttl, mediaTokenTtlSeconds)`)
 * guarantees the signature outlives the cache entry — no signing change was
 * needed for this task. The byte-equality test below uploads a real logo so
 * the signed URLs are part of the compared bytes: the tokens are
 * time-derived, never viewer-derived, so they are bucket-safe; because the
 * expiry ticks per second, the test retries until all three fetches land in
 * the same wall-clock second.
 *
 * The field-set test below freezes the top-level `community:read` field set.
 * If it fails, a field was added to (or removed from) the group: before
 * updating CERTIFIED_FIELDS, prove the new field is viewer-agnostic, or it
 * will silently leak one viewer's data into another viewer's cached page.
 */
class CommunitySerializationCanaryTest extends ApiTestCase
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
        'id',
        'identifier',
        'name',
        'isPrivate',
        'hostname',
        'description',
        'accentColor',
        'broadcastMentionMinRole',
        'locale',
        'welcomeChannel',
        'logo',
        'memberCount',
        'channels',
        'channelSections',
    ];

    public function testMemberCountReflectsMembership(): void
    {
        $community = CommunityFactory::new()->withIdentifier('canary-comm-count')->create();
        $viewer = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($viewer, $community);
        CommunityMemberFactory::createForUserAndCommunity(UserFactory::createOne(), $community);

        $data = $this->jsonClient($viewer)->request('GET', '/api/v1/communities/canary-comm-count')->toArray();

        self::assertSame(2, $data['memberCount']);
    }

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    public function testCommunityReadFieldSetMatchesCertifiedList(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('canary-comm-fields')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/canary-comm-fields');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();

        $actualFields = array_keys($data);
        sort($actualFields);
        $certified = self::CERTIFIED_FIELDS;
        sort($certified);

        self::assertSame(
            $certified,
            $actualFields,
            'community:read serialized field set changed — see class docblock before updating CERTIFIED_FIELDS.',
        );
    }

    /**
     * The `isPrivate` flag must reach the wire: its getter used to be named
     * identically to the property (`isPrivate()`), which Symfony's property
     * accessor only resolved through its bare-name ("jQuery-style") branch —
     * a path that silently dropped the attribute from every serialized
     * response despite the `community:read` group on the property. Fixed by
     * adding a conventional `isPrivate()` accessor; this pins the fix for
     * both values of the flag.
     */
    public function testIsPrivateSerializesInCommunityDetail(): void
    {
        $member = UserFactory::createOne();
        $private = CommunityFactory::new()->private()->withIdentifier('canary-comm-priv-flag')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $private);
        CommunityFactory::new()->withIdentifier('canary-comm-pub-flag')->create();

        $this->jsonClient($member)->request('GET', '/api/v1/communities/canary-comm-priv-flag');
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['isPrivate' => true]);

        $this->jsonClient()->request('GET', '/api/v1/communities/canary-comm-pub-flag');
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['isPrivate' => false]);
    }

    /**
     * The real cache-safety guard: a cache-flagged community detail response
     * is stored once per bucket and replayed to every viewer in that bucket.
     * This fetches the same PUBLIC community (with a signed-URL logo and one
     * public + one private channel) as three different viewers who all
     * belong to the same "standard" bucket — a plain member, a stranger who
     * has never joined, and an anonymous (unauthenticated) caller — and
     * asserts the response bytes are byte-identical across all three, AND
     * that none of them can see the private channel.
     *
     * The logo's signed `sign()` tokens embed a `time() + ttl` expiry, so
     * the compared bytes are time-dependent (never viewer-dependent): the
     * three fetches are retried until they land in the same wall-clock
     * second, then compared. See the class docblock.
     *
     * Before the strip, this assertion was RED: the member's payload carried
     * `isMember: true, currentUserHasMembership: true, currentUserRole:
     * "member"` while the stranger's carried all-false/null and the
     * anonymous caller's omitted them from consideration entirely — three
     * different byte strings for what must be one shared cache entry.
     */
    public function testResponseIsByteIdenticalAcrossStandardBucketViewers(): void
    {
        $member = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $communityAdmin = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('canary-comm-bucket')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createOne(['user' => $communityAdmin, 'community' => $community, 'role' => CommunityRole::Admin]);

        $publicChannel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'canary-comm-public'])->create();
        $privateChannel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'canary-comm-private'])->create();

        $tmp = tempnam(sys_get_temp_dir(), 'canary_logo_');
        file_put_contents((string) $tmp, $this->minimalPng());
        $file = new UploadedFile((string) $tmp, 'canary-logo.png', 'image/png', null, true);
        $this->uploadClient($communityAdmin)->request(
            'POST',
            '/api/v1/communities/canary-comm-bucket/logo',
            ['extra' => ['files' => ['file' => $file]]],
        );
        self::assertResponseStatusCodeSame(201);

        $path = '/api/v1/communities/canary-comm-bucket';

        // The signed logo tokens tick per second — retry until all three
        // fetches share one wall-clock second so the tokens are comparable.
        $attempt = 0;
        do {
            $second = time();
            $bodies = [];
            $bodies['member'] = $this->jsonClient($member)->request('GET', $path)->getContent();
            self::assertResponseIsSuccessful();
            $bodies['stranger'] = $this->jsonClient($stranger)->request('GET', $path)->getContent();
            self::assertResponseIsSuccessful();
            $bodies['anon'] = $this->jsonClient()->request('GET', $path)->getContent();
            self::assertResponseIsSuccessful();
        } while (time() !== $second && ++$attempt < 5);

        if (time() !== $second) {
            self::fail('the three fetches never landed in the same wall-clock second after 5 attempts — this is a clock-straddle retry exhaustion (the signed-URL expiry ticked mid-fetch), not a genuine per-viewer-state mismatch; rerun the test.');
        }

        self::assertStringContainsString('"logo":{', $bodies['member'], 'the fixture must actually embed a logo — otherwise the byte-equality never covers the signed URLs.');

        self::assertSame(
            $bodies['stranger'],
            $bodies['member'],
            'A plain member and a non-member must see byte-identical community detail responses — any divergence is per-viewer state that cannot ride a shared cache bucket.',
        );
        self::assertSame(
            $bodies['stranger'],
            $bodies['anon'],
            'An anonymous caller and a non-member must see byte-identical community detail responses on a public community — any divergence is per-viewer state that cannot ride a shared cache bucket.',
        );

        foreach ($bodies as $label => $body) {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            $channelIds = array_column($data['channels'], 'id');
            self::assertContains($publicChannel->getId(), $channelIds, "the public channel must be visible to {$label}");
            self::assertNotContains($privateChannel->getId(), $channelIds, "the private channel must NEVER be visible to {$label}");
        }
    }

    /**
     * Fingerprint-side probe: a user holding a private-channel grant sees a
     * DIFFERENT channel list (the private channel present) than the standard
     * bucket above. This is why grant-holders must be routed to a distinct
     * ("fingerprint") cache bucket rather than the shared standard one — if
     * they shared the standard bucket, either their view would wrongly hide
     * the private channel, or (worse) the private channel would leak into
     * every standard-bucket viewer's cached response.
     */
    public function testGrantHolderSeesDifferentChannelListThanStandardBucket(): void
    {
        $grantHolder = UserFactory::createOne();
        $stranger = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('canary-comm-grant')->create();
        CommunityMemberFactory::createForUserAndCommunity($grantHolder, $community);
        CommunityMemberFactory::createForUserAndCommunity($stranger, $community);

        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'canary-comm-grant-public'])->create();
        $privateChannel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'canary-comm-grant-private'])->create();
        ChannelMemberFactory::createForUserAndChannel($grantHolder, $privateChannel, ChannelRole::Member);

        $path = '/api/v1/communities/canary-comm-grant';

        $standardResponse = $this->jsonClient($stranger)->request('GET', $path);
        self::assertResponseIsSuccessful();
        $standardChannelIds = array_column($standardResponse->toArray()['channels'], 'id');

        $grantResponse = $this->jsonClient($grantHolder)->request('GET', $path);
        self::assertResponseIsSuccessful();
        $grantChannelIds = array_column($grantResponse->toArray()['channels'], 'id');

        self::assertNotContains($privateChannel->getId(), $standardChannelIds, 'the standard bucket must not see the private channel');
        self::assertContains($privateChannel->getId(), $grantChannelIds, 'the grant-holder must see the private channel');
        self::assertNotSame(
            $standardChannelIds,
            $grantChannelIds,
            'a private-channel grant-holder must see a DIFFERENT channel list than the standard bucket — this is why grant-holders need their own cache bucket, never the shared standard one.',
        );
    }
}
