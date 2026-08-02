<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
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
 * Verifies that /media/<token>/<filename> routes enforce signed-URL tokens.
 */
class MediaObjectSignedUrlTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    /** @return array{User, string} [$user, contentUrl with signed token in path] */
    private function uploadAndLinkAttachment(): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('su-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'su-ch', 'allowAttachments' => true])
            ->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $tmp = tempnam(sys_get_temp_dir(), 'su_test_');
        file_put_contents((string) $tmp, $this->minimalPng());
        $file = new UploadedFile((string) $tmp, 'test.png', 'image/png', null, true);

        $uploadResponse = $this->uploadClient($user)->request(
            'POST',
            '/api/v1/communities/su-c/channels/su-ch/attachments',
            ['extra' => ['files' => ['file' => $file]]],
        );
        self::assertResponseStatusCodeSame(201);

        $attachmentIri = $uploadResponse->toArray()['@id'];

        $msgResponse = $this->jsonClient($user)->request('POST', '/api/v1/communities/su-c/channels/su-ch/messages', [
            'json' => ['text' => 'signed url test', 'attachmentIris' => [$attachmentIri]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return [$user, (string) $msgResponse->toArray()['attachments'][0]['contentUrl']];
    }

    /**
     * URL format: https://host/media/{token}/{filename}
     * Segments[0]="" [1]="media" [2]=token [3]=filename.
     */
    private function replaceTokenInPath(string $absoluteUrl, string $newToken): string
    {
        $path = (string) parse_url($absoluteUrl, PHP_URL_PATH);
        $segments = explode('/', $path);
        // segment[2] is the token (after the leading empty string and "media")
        $segments[2] = $newToken;

        return implode('/', $segments);
    }

    public function testContentUrlHasTokenInPath(): void
    {
        [, $contentUrl] = $this->uploadAndLinkAttachment();

        // URL must look like /media/{token}/{filename} — no query string
        $path = (string) parse_url($contentUrl, PHP_URL_PATH);
        self::assertMatchesRegularExpression('#^/media/[0-9a-f]{64}\.[0-9]+/.+$#', $path);
        self::assertNull(parse_url($contentUrl, PHP_URL_QUERY));
    }

    public function testValidTokenAllowsAnonymousAccess(): void
    {
        // A valid signed token lets even anonymous users fetch a public channel attachment
        [, $contentUrl] = $this->uploadAndLinkAttachment();
        $path = (string) parse_url($contentUrl, PHP_URL_PATH);

        static::createClient()->request('GET', $path);

        self::assertResponseStatusCodeSame(200);
    }

    public function testMissingTokenSegmentReturns404(): void
    {
        [$user, $contentUrl] = $this->uploadAndLinkAttachment();

        // Strip the token segment: /media/{token}/{filename} → /media/{filename}
        $path = (string) parse_url($contentUrl, PHP_URL_PATH);
        $segments = explode('/', $path); // ['', 'media', token, filename]
        array_splice($segments, 2, 1);   // remove token → ['', 'media', filename]
        $pathWithoutToken = implode('/', $segments);

        $this->jsonClient($user)->request('GET', $pathWithoutToken);

        // No route matches /media/{single_segment} so Symfony returns 404
        self::assertResponseStatusCodeSame(404);
    }

    public function testTamperedTokenReturns404(): void
    {
        [$user, $contentUrl] = $this->uploadAndLinkAttachment();

        // Flip one hex character in the HMAC portion of the token to break the signature
        $path = (string) parse_url($contentUrl, PHP_URL_PATH);
        $segments = explode('/', $path);
        $token = $segments[2];
        $token[0] = 'a' === $token[0] ? '0' : 'a';
        $segments[2] = $token;

        $this->jsonClient($user)->request('GET', implode('/', $segments));

        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredTokenReturns404(): void
    {
        [$user, $contentUrl] = $this->uploadAndLinkAttachment();

        // Derive the relative path the signing service uses: strip /media/{token}/
        $path = (string) parse_url($contentUrl, PHP_URL_PATH);
        $segments = explode('/', $path); // ['', 'media', token, filename]
        $filename = implode('/', array_slice($segments, 3)); // everything after token

        // Build a correctly-signed but already-expired token
        $expiry = time() - 1;
        $signingKey = (string) ($_ENV['APP_MEDIA_SIGNING_KEY'] ?? '');
        $hmac = hash_hmac('sha256', $filename.':'.$expiry, $signingKey);
        $expiredToken = $hmac.'.'.$expiry;

        $tamperedPath = $this->replaceTokenInPath($contentUrl, $expiredToken);
        $this->jsonClient($user)->request('GET', $tamperedPath);

        self::assertResponseStatusCodeSame(404);
    }
}
