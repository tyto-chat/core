<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Community\CommunityRole;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The HTTP cache layer caches `community_emoji:read` responses per-bucket
 * instead of per-viewer (see HTTP_CACHE_LAYER_PLAN.md risk #2). That is only
 * safe if every serialized byte is viewer-agnostic (same value for every
 * caller who can see the emoji list at all).
 *
 * The authoritative guard is testCreatedByIsByteIdenticalAcrossViewers below:
 * it fetches the same list as three viewers and asserts the bytes match. It
 * exists because that safety was NOT self-evidently true when the field-set
 * canary was first written — the `createdBy` relation used to be an untyped
 * Blameable property, and `UserNormalizer` injects the self/admin
 * `user:read:self` group (email) for the creating admin and any admin viewer.
 * `createdBy` is now resolved via the typed getter to a bare JSON-LD
 * reference (identifier + type keys only), so no User field can ride the
 * shared cache.
 *
 * The field-set tests below freeze the top-level emoji field set plus the
 * embedded `image` MediaObject sub-object (the signed `contentUrl` is the
 * cache-coherency guarantee — its shape is load-bearing, not incidental). If
 * either fails, a field was added to (or removed from) `community_emoji:read`:
 * before updating CERTIFIED_FIELDS / CERTIFIED_IMAGE_FIELDS, prove the new
 * field is viewer-agnostic, or it will silently leak one viewer's data into
 * another viewer's cached page.
 */
class CommunityEmojiSerializationCanaryTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @var list<string>
     */
    private const array CERTIFIED_FIELDS = [
        '@id',
        '@type',
        'id',
        'shortcode',
        'name',
        'image',
        'position',
        'createdBy',
        'createdAt',
        'updatedAt',
    ];

    /**
     * @var list<string>
     */
    private const array CERTIFIED_IMAGE_FIELDS = [
        '@id',
        '@type',
        'contentUrl',
        'mimeType',
        'width',
        'height',
    ];

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    public function testCommunityEmojiFieldSetMatchesCertifiedList(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('canary-emoji-c')->create();
        CommunityMemberFactory::new()->with(['user' => $user, 'community' => $community, 'role' => CommunityRole::Admin])->create();

        $tmp = tempnam(sys_get_temp_dir(), 'canary_emoji_');
        file_put_contents((string) $tmp, $this->minimalPng());
        $file = new UploadedFile((string) $tmp, 'canary.png', 'image/png', null, true);

        $uploadResponse = $this->uploadClient($user)->request(
            'POST',
            '/api/v1/communities/canary-emoji-c/emojis/custom',
            [
                'extra' => [
                    'files' => ['file' => $file],
                    'parameters' => ['shortcode' => ':canary_emoji:'],
                ],
            ],
        );
        self::assertResponseStatusCodeSame(201);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/canary-emoji-c/emojis');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertNotEmpty($data['hydra:member']);
        $emoji = $data['hydra:member'][0];

        $actualFields = array_keys($emoji);
        sort($actualFields);
        $certified = self::CERTIFIED_FIELDS;
        sort($certified);

        self::assertSame(
            $certified,
            $actualFields,
            'community_emoji:read serialized field set changed — see class docblock before updating CERTIFIED_FIELDS.',
        );

        self::assertArrayHasKey('image', $emoji);
        $actualImageFields = array_keys((array) $emoji['image']);
        sort($actualImageFields);
        $certifiedImage = self::CERTIFIED_IMAGE_FIELDS;
        sort($certifiedImage);

        self::assertSame(
            $certifiedImage,
            $actualImageFields,
            'community_emoji:read embedded image field set changed — see class docblock before updating CERTIFIED_IMAGE_FIELDS.',
        );
    }

    /**
     * The real cache-safety guard: a cache-flagged emoji list is stored once
     * per bucket and replayed to every viewer in it, so the serialized bytes
     * MUST NOT depend on WHO fetched them. This uploads an emoji as a community
     * admin (making that admin the emoji's `createdBy`), then fetches the list
     * as three viewers — the creating admin, a plain member, and a global
     * ROLE_ADMIN — and asserts the serialized `createdBy` is byte-identical.
     *
     * `createdBy` serializes as a bare JSON-LD reference (identifier + type
     * keys only): the User relation is exposed via the typed
     * `getCreatedBy(): ?User` getter and no User field lives in the
     * `community_emoji:read` group, so nothing but the identifier is emitted —
     * viewer-agnostic by construction, leaking no PII regardless of who
     * fetches the list.
     */
    public function testCreatedByIsByteIdenticalAcrossViewers(): void
    {
        $creatorAdmin = UserFactory::createOne();
        $member = UserFactory::createOne();
        $globalAdmin = UserFactory::new()->admin()->create();

        $community = CommunityFactory::new()->withIdentifier('canary-emoji-xv')->create();
        CommunityMemberFactory::new()->with(['user' => $creatorAdmin, 'community' => $community, 'role' => CommunityRole::Admin])->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($globalAdmin, $community);

        $tmp = tempnam(sys_get_temp_dir(), 'canary_emoji_xv_');
        file_put_contents((string) $tmp, $this->minimalPng());
        $file = new UploadedFile((string) $tmp, 'canary.png', 'image/png', null, true);

        $this->uploadClient($creatorAdmin)->request(
            'POST',
            '/api/v1/communities/canary-emoji-xv/emojis/custom',
            [
                'extra' => [
                    'files' => ['file' => $file],
                    'parameters' => ['shortcode' => ':canary_xv:'],
                ],
            ],
        );
        self::assertResponseStatusCodeSame(201);

        $path = '/api/v1/communities/canary-emoji-xv/emojis';

        $createdBy = [];
        foreach (['creator' => $creatorAdmin, 'member' => $member, 'admin' => $globalAdmin] as $label => $viewer) {
            $response = $this->jsonClient($viewer)->request('GET', $path);
            self::assertResponseIsSuccessful();
            $emoji = $response->toArray()['hydra:member'][0];
            self::assertArrayHasKey('createdBy', $emoji);
            $createdBy[$label] = json_encode($emoji['createdBy'], \JSON_THROW_ON_ERROR);
        }

        self::assertSame(
            $createdBy['member'],
            $createdBy['creator'],
            'The emoji creator fetching a cache-flagged list must serialize createdBy identically to a plain member.',
        );
        self::assertSame(
            $createdBy['member'],
            $createdBy['admin'],
            'A global admin fetching a cache-flagged list must serialize createdBy identically to a plain member.',
        );

        // Freeze the viewer-agnostic shape: createdBy is a bare JSON-LD
        // reference (identifier + type only), never an embedded User payload.
        $neutral = json_decode($createdBy['member'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($neutral);
        $fields = array_keys($neutral);
        sort($fields);
        self::assertSame(['@id', '@type'], $fields, 'createdBy must serialize as a bare {@id,@type} reference — a wider shape risks leaking a User field into the shared cache.');
        self::assertStringStartsWith('/api/v1/users/', (string) $neutral['@id']);
        self::assertArrayNotHasKey('email', $neutral);
    }
}
