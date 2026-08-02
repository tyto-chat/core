<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AuthTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testLoginReturnsToken(): void
    {
        UserFactory::new()->withPassword('secret123')->with(['email' => 'login@example.com'])->create();

        $client = static::createClient();
        $response = $client->request('POST', '/auth', [
            'json' => ['email' => 'login@example.com', 'password' => 'secret123'],
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        UserFactory::createOne(['email' => 'user@example.com']);

        $client = static::createClient();
        $client->request('POST', '/auth', [
            'json' => ['email' => 'user@example.com', 'password' => 'wrongpassword'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginWithUnknownEmailReturns401(): void
    {
        $client = static::createClient();
        $client->request('POST', '/auth', [
            'json' => ['email' => 'nobody@example.com', 'password' => 'irrelevant'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenRefreshWithInvalidTokenReturns401(): void
    {
        static::createClient()->request('POST', '/token/refresh', [
            'json' => ['refresh_token' => 'invalid-token'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenRefreshWithMissingTokenReturns401(): void
    {
        static::createClient()->request('POST', '/token/refresh', ['json' => []]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshKeepsPersistentCookieForRememberMeLogin(): void
    {
        UserFactory::new()->withPassword('secret123')->with(['email' => 'remember@example.com'])->create();

        $client = static::createClient();
        $response = $client->request('POST', '/auth', [
            'json' => ['email' => 'remember@example.com', 'password' => 'secret123', 'remember_me' => true],
        ]);

        $loginSetCookies = $response->getHeaders(false)['set-cookie'] ?? [];
        $loginCookie = $this->findSetCookie($loginSetCookies, 'refresh_token');
        self::assertNotNull($loginCookie);
        self::assertStringContainsStringIgnoringCase('expires=', $loginCookie);

        $refreshResponse = $client->request('POST', '/token/refresh', [
            'headers' => ['Cookie' => $this->allCookiePairs($loginSetCookies)],
        ]);

        self::assertResponseStatusCodeSame(200);
        $rotatedCookie = $this->findSetCookie($refreshResponse->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNotNull($rotatedCookie);
        self::assertStringContainsStringIgnoringCase(
            'expires=',
            $rotatedCookie,
            'Rotated refresh cookie must stay persistent for remember-me sessions',
        );
    }

    public function testRefreshKeepsSessionCookieForPlainLogin(): void
    {
        UserFactory::new()->withPassword('secret123')->with(['email' => 'plain@example.com'])->create();

        $client = static::createClient();
        $response = $client->request('POST', '/auth', [
            'json' => ['email' => 'plain@example.com', 'password' => 'secret123'],
        ]);

        $loginSetCookies = $response->getHeaders(false)['set-cookie'] ?? [];
        $loginCookie = $this->findSetCookie($loginSetCookies, 'refresh_token');
        self::assertNotNull($loginCookie);
        self::assertStringNotContainsStringIgnoringCase('expires=', $loginCookie);

        $refreshResponse = $client->request('POST', '/token/refresh', [
            'headers' => ['Cookie' => $this->allCookiePairs($loginSetCookies)],
        ]);

        self::assertResponseStatusCodeSame(200);
        $rotatedCookie = $this->findSetCookie($refreshResponse->getHeaders(false)['set-cookie'] ?? [], 'refresh_token');
        self::assertNotNull($rotatedCookie);
        self::assertStringNotContainsStringIgnoringCase(
            'expires=',
            $rotatedCookie,
            'Rotated refresh cookie must stay a session cookie for non-remember-me sessions',
        );
    }

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

    /**
     * Builds a Cookie request header from every Set-Cookie line, the way a
     * browser would send them back.
     *
     * @param string[] $setCookies
     */
    private function allCookiePairs(array $setCookies): string
    {
        return implode('; ', array_map(static fn (string $c): string => explode(';', $c, 2)[0], $setCookies));
    }

    public function testLogoutReturns204(): void
    {
        static::createClient()->request('POST', '/logout');

        self::assertResponseStatusCodeSame(204);
    }

    public function testLogoutClearsRefreshTokenAndBearerCookies(): void
    {
        $response = static::createClient()->request('POST', '/logout');

        $cookies = $response->getHeaders(false)['set-cookie'] ?? [];
        $refreshCookie = null;
        $bearerCookie = null;
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, 'refresh_token=')) {
                $refreshCookie = $cookie;
            }
            if (str_starts_with($cookie, 'BEARER=')) {
                $bearerCookie = $cookie;
            }
        }

        self::assertNotNull($refreshCookie);
        self::assertStringContainsString('Max-Age=0', $refreshCookie);
        self::assertStringContainsStringIgnoringCase('samesite=none', $refreshCookie);
        self::assertStringContainsStringIgnoringCase('secure', $refreshCookie);

        self::assertNotNull($bearerCookie);
        self::assertStringContainsString('Max-Age=0', $bearerCookie);
        self::assertStringContainsStringIgnoringCase('samesite=none', $bearerCookie);
    }
}
