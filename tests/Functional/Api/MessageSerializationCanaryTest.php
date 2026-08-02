<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The HTTP cache layer caches `message:read` responses per-bucket instead of
 * per-viewer (see HTTP_CACHE_LAYER_PLAN.md risk #2). That is only safe
 * because every field currently in the group is viewer-agnostic (same value
 * for every caller who can see the message at all) — nothing per-viewer
 * (e.g. "did I react", "is this unread for me") ever slipped in.
 *
 * This test freezes the exact top-level field set of a serialized message.
 * If it fails, a field was added to (or removed from) `message:read`: before
 * updating CERTIFIED_FIELDS, prove the new field is viewer-agnostic, or it
 * will silently leak one viewer's data into another viewer's cached page.
 */
class MessageSerializationCanaryTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @var list<string>
     */
    private const array CERTIFIED_FIELDS = [
        '@id',
        '@type',
        'reactions',
        'parent',
        'isDeleted',
        'kind',
        'deletedBy',
        'text',
        'createdAt',
        'createdBy',
        'deletedAt',
        'pinnedAt',
        'pinnedBy',
        'replyCount',
        'lastReplyAt',
        'purgedAttachmentCount',
        'attachments',
        'edited',
        'pinned',
        'pageNumber',
        'communityIdentifier',
        'channelIdentifier',
        'conversationIdentifier',
    ];

    public function testMessageReadFieldSetMatchesCertifiedList(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('canary-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'canary-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($user)->withText('canary')->create();

        $response = $this->jsonClient($user)->request(
            'GET',
            '/api/v1/communities/canary-c/channels/canary-ch/pages/'.$page->getPageNumber(),
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertNotEmpty($data['messages']);
        $message = $data['messages'][0];

        $actualFields = array_keys($message);
        sort($actualFields);
        $certified = self::CERTIFIED_FIELDS;
        sort($certified);

        self::assertSame(
            $certified,
            $actualFields,
            'message:read serialized field set changed — see class docblock before updating CERTIFIED_FIELDS.',
        );
    }

    /**
     * @var list<string>
     */
    private const array CERTIFIED_CREATED_BY_FIELDS = [
        '@id',
        '@type',
        'id',
        'isAdmin',
        'profile',
    ];

    /**
     * The real cache-safety guard: a cache-flagged page is stored once per
     * bucket and replayed to every viewer in it. So the serialized bytes MUST
     * NOT depend on WHO fetched them. This fetches the same channel page as
     * three different viewers — the message author (a plain member), another
     * plain member, and a ROLE_ADMIN — and asserts the embedded `createdBy`
     * sub-object is byte-identical across all three.
     *
     * Before the fix, `UserNormalizer` injected the `user:read:self` group
     * (which carries `email`) whenever the serialized user was the caller OR
     * the caller was an admin — so the author's own fetch and the admin's
     * fetch embedded `email` into `createdBy`, while the neutral member's did
     * not. That per-viewer divergence is exactly what a shared cache bucket
     * must never contain.
     */
    public function testCreatedBySubObjectIsByteIdenticalAcrossViewers(): void
    {
        $author = UserFactory::createOne();
        $otherMember = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();

        $community = CommunityFactory::new()->withIdentifier('canary-xv-c')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'canary-xv-ch'])->create();

        foreach ([$author, $otherMember, $admin] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
            ChannelMemberFactory::createForUserAndChannel($u, $channel);
        }

        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($author)->withText('cross-viewer canary')->create();

        $path = '/api/v1/communities/canary-xv-c/channels/canary-xv-ch/pages/'.$page->getPageNumber();

        $createdByJson = [];
        foreach (['author' => $author, 'other' => $otherMember, 'admin' => $admin] as $label => $viewer) {
            $response = $this->jsonClient($viewer)->request('GET', $path);
            self::assertResponseIsSuccessful();
            $message = $response->toArray()['messages'][0];
            self::assertArrayHasKey('createdBy', $message);
            self::assertIsArray($message['createdBy'], 'createdBy must embed as an object, not an IRI string.');
            $createdByJson[$label] = json_encode($message['createdBy'], \JSON_THROW_ON_ERROR);
        }

        self::assertSame(
            $createdByJson['other'],
            $createdByJson['author'],
            'The author fetching a cache-flagged page must serialize createdBy identically to a neutral member — no self-only fields (email) may ride a shared cache bucket.',
        );
        self::assertSame(
            $createdByJson['other'],
            $createdByJson['admin'],
            'An admin fetching a cache-flagged page must serialize createdBy identically to a plain member — no admin-only fields may ride a shared cache bucket.',
        );

        // Freeze the createdBy sub-shape from the NON-privileged viewer's
        // perspective: this is the canonical viewer-agnostic embed.
        $neutralCreatedBy = json_decode($createdByJson['other'], true, 512, \JSON_THROW_ON_ERROR);
        $actualCreatedByFields = array_keys($neutralCreatedBy);
        sort($actualCreatedByFields);
        $certifiedCreatedBy = self::CERTIFIED_CREATED_BY_FIELDS;
        sort($certifiedCreatedBy);

        self::assertSame(
            $certifiedCreatedBy,
            $actualCreatedByFields,
            'createdBy sub-object field set changed — a new field here rides the shared cache; prove it is viewer-agnostic before updating CERTIFIED_CREATED_BY_FIELDS.',
        );
        self::assertArrayNotHasKey('email', $neutralCreatedBy);
    }

    /**
     * The `/messages/{id}/thread` GetCollection has no explicit
     * normalizationContext override on the entity, so it inherits the
     * class-level `message:read` group — identical to the channel-page read
     * path above. This freezes that a reply item's field set matches the
     * SAME CERTIFIED_FIELDS list (no thread-only field silently added,
     * e.g. a per-viewer "read in thread" flag).
     */
    public function testThreadReplyFieldSetMatchesCertifiedMessageFields(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('canary-thr-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'canary-thr-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('canary root')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'canary reply'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId().'/thread');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $items = $data['member'] ?? $data['hydra:member'] ?? [];
        self::assertCount(1, $items);
        $reply = $items[0];

        $actualFields = array_keys($reply);
        sort($actualFields);
        $certified = self::CERTIFIED_FIELDS;
        sort($certified);

        self::assertSame(
            $certified,
            $actualFields,
            'thread reply field set diverged from message:read CERTIFIED_FIELDS — see class docblock before updating either list.',
        );
    }

    /**
     * Same cache-safety guard as testCreatedBySubObjectIsByteIdenticalAcrossViewers,
     * applied to a reply fetched via the thread endpoint (which the HTTP
     * cache also flags — see HTTP_CACHE_LAYER_PLAN.md). Fetches the same
     * thread as three viewers — the reply author, another plain member, and
     * a ROLE_ADMIN — and asserts the embedded `createdBy` sub-object is
     * byte-identical across all three.
     */
    public function testThreadReplyCreatedBySubObjectIsByteIdenticalAcrossViewers(): void
    {
        $author = UserFactory::createOne();
        $otherMember = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();

        $community = CommunityFactory::new()->withIdentifier('canary-thr-xv-c')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'canary-thr-xv-ch'])->create();

        foreach ([$author, $otherMember, $admin] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
            ChannelMemberFactory::createForUserAndChannel($u, $channel);
        }

        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($author)->withText('canary xv root')->create();

        $this->jsonClient($author)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'canary xv reply'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $path = '/api/v1/messages/'.$root->getId().'/thread';

        $createdByJson = [];
        foreach (['author' => $author, 'other' => $otherMember, 'admin' => $admin] as $label => $viewer) {
            $response = $this->jsonClient($viewer)->request('GET', $path);
            self::assertResponseIsSuccessful();
            $data = $response->toArray();
            $items = $data['member'] ?? $data['hydra:member'] ?? [];
            self::assertCount(1, $items);
            $reply = $items[0];
            self::assertArrayHasKey('createdBy', $reply);
            self::assertIsArray($reply['createdBy'], 'createdBy must embed as an object, not an IRI string.');
            $createdByJson[$label] = json_encode($reply['createdBy'], \JSON_THROW_ON_ERROR);
        }

        self::assertSame(
            $createdByJson['other'],
            $createdByJson['author'],
            'The reply author fetching a cache-flagged thread must serialize createdBy identically to a neutral member — no self-only fields (email) may ride a shared cache bucket.',
        );
        self::assertSame(
            $createdByJson['other'],
            $createdByJson['admin'],
            'An admin fetching a cache-flagged thread must serialize createdBy identically to a plain member — no admin-only fields may ride a shared cache bucket.',
        );
    }
}
