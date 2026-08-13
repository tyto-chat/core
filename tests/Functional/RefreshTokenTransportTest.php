<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Factory\UserFactory;
use OTPHP\TOTP;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class RefreshTokenTransportTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @param string[] $setCookies */
    private function findSetCookie(array $setCookies, string $name): ?string
    {
        foreach ($setCookies as $cookie) {
            if (str_starts_with($cookie, $name.'=')) {
                return $cookie;
            }
        }

        return null;
    }

    public function testLoginWithoutHeaderKeepsCookieTransport(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'nohdr@example.com']);

        $response = static::createClient()->request('POST', '/auth', [
            'json' => ['email' => 'nohdr@example.com', 'password' => 'Sekret1!'],
        ]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertArrayHasKey('token', $body);
        self::assertArrayNotHasKey('refresh_token', $body);

        $cookie = $this->findSetCookie($response->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNotNull($cookie);
    }

    public function testLoginWithBodyHeaderReturnsTokenInBodyOnly(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'bodyhdr@example.com']);

        $response = static::createClient()->request('POST', '/auth', [
            'headers' => ['X-Token-Transport' => 'body'],
            'json' => ['email' => 'bodyhdr@example.com', 'password' => 'Sekret1!'],
        ]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertArrayHasKey('refresh_token', $body);
        self::assertNotEmpty($body['refresh_token']);

        $cookie = $this->findSetCookie($response->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNull($cookie);
    }

    public function testRefreshWithBodyHeaderUsingBodyTokenRotatesInBody(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'bodyrefresh@example.com']);

        $client = static::createClient();
        $login = $client->request('POST', '/auth', [
            'headers' => ['X-Token-Transport' => 'body'],
            'json' => ['email' => 'bodyrefresh@example.com', 'password' => 'Sekret1!'],
        ])->toArray();

        $refreshResponse = $client->request('POST', '/token/refresh', [
            'headers' => ['X-Token-Transport' => 'body'],
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);

        self::assertResponseIsSuccessful();
        $refreshBody = $refreshResponse->toArray();
        self::assertArrayHasKey('token', $refreshBody);
        self::assertArrayHasKey('refresh_token', $refreshBody);
        self::assertNotEmpty($refreshBody['refresh_token']);
        self::assertNotSame($login['refresh_token'], $refreshBody['refresh_token']);

        $cookie = $this->findSetCookie($refreshResponse->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNull($cookie);
    }

    public function testRefreshWithBodyHeaderAndCookieIgnoresHeaderAndKeepsCookieFlow(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'cookieandbody@example.com']);

        $client = static::createClient();
        $login = $client->request('POST', '/auth', [
            'json' => ['email' => 'cookieandbody@example.com', 'password' => 'Sekret1!'],
        ]);
        $loginSetCookies = $login->getHeaders(false)['set-cookie'] ?? [];
        $loginCookie = $this->findSetCookie($loginSetCookies, 'refresh_token');
        self::assertNotNull($loginCookie);
        $cookiePairs = implode('; ', array_map(
            static fn (string $c): string => explode(';', $c, 2)[0],
            $loginSetCookies,
        ));

        $refreshResponse = $client->request('POST', '/token/refresh', [
            'headers' => [
                'X-Token-Transport' => 'body',
                'Cookie' => $cookiePairs,
            ],
        ]);

        self::assertResponseIsSuccessful();
        $refreshBody = $refreshResponse->toArray();
        self::assertArrayHasKey('token', $refreshBody);
        self::assertArrayNotHasKey('refresh_token', $refreshBody);

        $rotatedCookie = $this->findSetCookie($refreshResponse->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNotNull($rotatedCookie);
        self::assertNotSame($loginCookie, $rotatedCookie);
    }

    public function testRefreshWithoutHeaderKeepsCookieFlow(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'cookierefresh@example.com']);

        $client = static::createClient();
        $login = $client->request('POST', '/auth', [
            'json' => ['email' => 'cookierefresh@example.com', 'password' => 'Sekret1!'],
        ]);
        $loginSetCookies = $login->getHeaders(false)['set-cookie'] ?? [];
        $loginCookie = $this->findSetCookie($loginSetCookies, 'refresh_token');
        self::assertNotNull($loginCookie);
        $cookiePairs = implode('; ', array_map(
            static fn (string $c): string => explode(';', $c, 2)[0],
            $loginSetCookies,
        ));

        $refreshResponse = $client->request('POST', '/token/refresh', [
            'headers' => ['Cookie' => $cookiePairs],
        ]);

        self::assertResponseIsSuccessful();
        $refreshBody = $refreshResponse->toArray();
        self::assertArrayHasKey('token', $refreshBody);
        self::assertArrayNotHasKey('refresh_token', $refreshBody);

        $rotatedCookie = $this->findSetCookie($refreshResponse->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNotNull($rotatedCookie);
    }

    /** @return array<string, mixed> */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        return json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
    }

    public function testTwoFactorLoginWithBodyHeaderKeepsPendingResponseTokenFree(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'totptransport@example.com']);

        $client = static::createClient();
        $pendingResponse = $client->request('POST', '/auth', [
            'headers' => ['X-Token-Transport' => 'body'],
            'json' => ['email' => 'totptransport@example.com', 'password' => 'Sekret1!'],
        ]);

        self::assertResponseIsSuccessful();
        $pendingBody = $pendingResponse->toArray();
        self::assertTrue($pendingBody['twoFactorRequired']);
        self::assertArrayNotHasKey('refresh_token', $pendingBody);
        self::assertNull($this->findSetCookie($pendingResponse->getHeaders(false)['set-cookie'] ?? [], 'refresh_token'));

        $code = TOTP::createFromSecret(UserFactory::TEST_TOTP_SECRET)->now();
        $verifyResponse = $client->request('POST', '/auth/2fa', [
            'headers' => [
                'Authorization' => 'Bearer '.$pendingBody['token'],
                'X-Token-Transport' => 'body',
                'Content-Type' => 'application/json',
            ],
            'json' => ['code' => $code],
        ]);

        self::assertResponseIsSuccessful();
        $verifyBody = $verifyResponse->toArray();
        self::assertArrayHasKey('refresh_token', $verifyBody);
        self::assertNotEmpty($verifyBody['refresh_token']);
        self::assertArrayNotHasKey('2fa_pending', $this->decodeJwtPayload($verifyBody['token']));
        self::assertNull($this->findSetCookie($verifyResponse->getHeaders(false)['set-cookie'] ?? [], 'refresh_token'));
    }

    public function testUnknownHeaderValueBehavesLikeNoHeader(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'banana@example.com']);

        $response = static::createClient()->request('POST', '/auth', [
            'headers' => ['X-Token-Transport' => 'banana'],
            'json' => ['email' => 'banana@example.com', 'password' => 'Sekret1!'],
        ]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertArrayNotHasKey('refresh_token', $body);

        $cookie = $this->findSetCookie($response->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNotNull($cookie);
    }

    public function testLogoutWithBodyTokenRevokesItForBodyTransportClient(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'bodylogout@example.com']);

        $client = static::createClient();
        $login = $client->request('POST', '/auth', [
            'headers' => ['X-Token-Transport' => 'body'],
            'json' => ['email' => 'bodylogout@example.com', 'password' => 'Sekret1!'],
        ])->toArray();

        $logoutResponse = $client->request('POST', '/logout', [
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(204, $logoutResponse->getContent(false));

        $client->request('POST', '/token/refresh', [
            'headers' => ['X-Token-Transport' => 'body'],
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutWithCookieStillRevokesToken(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'cookielogout@example.com']);

        $client = static::createClient();
        $login = $client->request('POST', '/auth', [
            'json' => ['email' => 'cookielogout@example.com', 'password' => 'Sekret1!'],
        ]);
        $loginSetCookies = $login->getHeaders(false)['set-cookie'] ?? [];
        $cookiePairs = implode('; ', array_map(
            static fn (string $c): string => explode(';', $c, 2)[0],
            $loginSetCookies,
        ));

        $logoutResponse = $client->request('POST', '/logout', [
            'headers' => ['Cookie' => $cookiePairs],
        ]);
        self::assertResponseStatusCodeSame(204, $logoutResponse->getContent(false));

        $client->request('POST', '/token/refresh', [
            'headers' => ['Cookie' => $cookiePairs],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutWithNeitherCookieNorBodyTokenReturns204(): void
    {
        $response = static::createClient()->request('POST', '/logout');

        self::assertResponseStatusCodeSame(204);
    }

    public function testLogoutWithMalformedBodyReturns204(): void
    {
        $response = static::createClient()->request('POST', '/logout', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => 'not-json',
        ]);

        self::assertResponseStatusCodeSame(204);
    }
}
