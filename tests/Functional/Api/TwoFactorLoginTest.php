<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OTPHP\TOTP;
use Symfony\Component\BrowserKit\Cookie;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TwoFactorLoginTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{body: array<string, mixed>, response: \Symfony\Contracts\HttpClient\ResponseInterface} */
    private function loginRequest(string $email, string $password): array
    {
        $response = static::createClient()->request('POST', '/auth', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['email' => $email, 'password' => $password],
        ]);

        return ['body' => $response->toArray(false), 'response' => $response];
    }

    /** @return array<string, mixed> */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        return json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
    }

    public function testLoginWithoutTwoFactorIsUnchanged(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'plain@example.com']);
        ['body' => $body] = $this->loginRequest('plain@example.com', 'Sekret1!');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $body);
        self::assertArrayNotHasKey('twoFactorRequired', $body);
        self::assertArrayNotHasKey('2fa_pending', $this->decodeJwtPayload($body['token']));
    }

    public function testLoginWithTwoFactorReturnsPendingToken(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'totp@example.com']);
        ['body' => $body, 'response' => $response] = $this->loginRequest('totp@example.com', 'Sekret1!');
        self::assertResponseIsSuccessful();
        self::assertTrue($body['twoFactorRequired']);
        self::assertArrayNotHasKey('refresh_token', $body);

        $payload = $this->decodeJwtPayload($body['token']);
        self::assertTrue($payload['2fa_pending']);
        self::assertLessThanOrEqual(time() + 300, $payload['exp']);
        self::assertGreaterThan(time() + 240, $payload['exp']);

        $cookieHeader = implode(' ', $response->getHeaders(false)['set-cookie'] ?? []);
        self::assertStringNotContainsString('BEARER=ey', $cookieHeader);
        self::assertStringNotContainsString('refresh_token=', str_replace('refresh_token=;', '', $cookieHeader));
        self::assertStringNotContainsString('remember_me=', $cookieHeader);
    }

    public function testNoRefreshTokenPersistedForPendingLogin(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'totp2@example.com']);
        $this->loginRequest('totp2@example.com', 'Sekret1!');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM refresh_tokens');
        self::assertSame(0, $count);
    }

    private function pendingToken(string $email, string $password): string
    {
        ['body' => $body] = $this->loginRequest($email, $password);

        return $body['token'];
    }

    public function testPendingTokenCannotAccessApi(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'guard@example.com']);
        $token = $this->pendingToken('guard@example.com', 'Sekret1!');

        static::createClient()->request('GET', '/api/v1/users/me/2fa', [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPendingTokenCanLogout(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'guard2@example.com']);
        $token = $this->pendingToken('guard2@example.com', 'Sekret1!');

        static::createClient()->request('POST', '/logout', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testFullTokenIsUnaffectedByGuard(): void
    {
        $user = UserFactory::new()->withTwoFactor()->create();
        $this->jsonClient($user)->request('GET', '/api/v1/users/me/2fa');
        self::assertResponseIsSuccessful();
    }

    public function testPendingTokenAsMediaCookieIsRejected(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'guard3@example.com']);
        $token = $this->pendingToken('guard3@example.com', 'Sekret1!');

        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('BEARER', $token));
        $client->request('GET', '/media/faketoken/file.png');
        self::assertResponseStatusCodeSame(401);
    }

    public function testFullTokenAsMediaCookieIsNotRejectedByGuard(): void
    {
        $user = UserFactory::new()->withTwoFactor()->create();
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('BEARER', $token));
        $client->request('GET', '/media/faketoken/file.png');
        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifyWithTotpCodeCompletesLogin(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'verify@example.com']);
        $pending = $this->pendingToken('verify@example.com', 'Sekret1!');

        $code = TOTP::createFromSecret(UserFactory::TEST_TOTP_SECRET)->now();
        $response = static::createClient()->request('POST', '/auth/2fa', [
            'headers' => ['Authorization' => 'Bearer '.$pending, 'Content-Type' => 'application/json'],
            'json' => ['code' => $code],
        ]);
        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertArrayNotHasKey('2fa_pending', $this->decodeJwtPayload($body['token']));

        $cookieHeader = implode(' ', $response->getHeaders()['set-cookie'] ?? []);
        self::assertStringContainsString('BEARER=', $cookieHeader);
        self::assertStringContainsString('refresh_token=', $cookieHeader);
    }

    public function testVerifyWithWrongCodeIsRejected(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'wrong@example.com']);
        $pending = $this->pendingToken('wrong@example.com', 'Sekret1!');

        static::createClient()->request('POST', '/auth/2fa', [
            'headers' => ['Authorization' => 'Bearer '.$pending, 'Content-Type' => 'application/json'],
            'json' => ['code' => '000000'],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testVerifyWithRecoveryCodeIsSingleUse(): void
    {
        $user = UserFactory::new()->withPassword('Sekret1!')->create(['email' => 'recovery@example.com']);
        $client = $this->jsonClient($user);
        $secret = $client->request('POST', '/api/v1/users/me/2fa/setup', ['json' => []])->toArray()['secret'];
        $code = TOTP::createFromSecret($secret)->now();
        $recoveryCodes = $client->request('POST', '/api/v1/users/me/2fa/confirm', ['json' => ['code' => $code]])
            ->toArray()['recoveryCodes'];

        $pending = $this->pendingToken('recovery@example.com', 'Sekret1!');
        static::createClient()->request('POST', '/auth/2fa', [
            'headers' => ['Authorization' => 'Bearer '.$pending, 'Content-Type' => 'application/json'],
            'json' => ['code' => $recoveryCodes[0]],
        ]);
        self::assertResponseIsSuccessful();

        $pending2 = $this->pendingToken('recovery@example.com', 'Sekret1!');
        static::createClient()->request('POST', '/auth/2fa', [
            'headers' => ['Authorization' => 'Bearer '.$pending2, 'Content-Type' => 'application/json'],
            'json' => ['code' => $recoveryCodes[0]],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create(['email' => 'replay@example.com']);
        $code = TOTP::createFromSecret(UserFactory::TEST_TOTP_SECRET)->now();

        $pending = $this->pendingToken('replay@example.com', 'Sekret1!');
        static::createClient()->request('POST', '/auth/2fa', [
            'headers' => ['Authorization' => 'Bearer '.$pending, 'Content-Type' => 'application/json'],
            'json' => ['code' => $code],
        ]);
        self::assertResponseIsSuccessful();

        $pending2 = $this->pendingToken('replay@example.com', 'Sekret1!');
        static::createClient()->request('POST', '/auth/2fa', [
            'headers' => ['Authorization' => 'Bearer '.$pending2, 'Content-Type' => 'application/json'],
            'json' => ['code' => $code],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTwoFactorLimiterRejectsAfterConfiguredAttempts(): void
    {
        // The `cache.rate_limiter` pool is array-backed in the test env and
        // gets reset (via kernel.reset) before every subsequent HTTP request
        // handled by the same client — real cross-request 429 assertions
        // aren't observable through the browser client here. Instead this
        // drives the exact same factory + key the controller uses directly
        // from the container, inside one PHP scope (no request boundary in
        // between), to prove the Settings-driven limit/interval (default
        // 5 / 900s, see Settings::rateTwoFactorLimit()) and the
        // `limiter.two_factor` service wiring in services.yaml actually
        // work end-to-end. `TwoFactorLoginControllerTest` (unit) covers the
        // controller's own branching on acceptance/rejection.
        $user = UserFactory::new()->withTwoFactor()->create();

        $limiter = static::getContainer()->get('limiter.two_factor');
        self::assertInstanceOf(\Symfony\Component\RateLimiter\RateLimiterFactoryInterface::class, $limiter);

        $key = '2fa-'.$user->getId();
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limiter->create($key)->consume()->isAccepted());
        }
        self::assertFalse($limiter->create($key)->consume()->isAccepted());
    }

    public function testVerifyWithoutTokenIsRejected(): void
    {
        static::createClient()->request('POST', '/auth/2fa', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['code' => '123456'],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testVerifyWithFullTokenIsRejected(): void
    {
        $user = UserFactory::new()->withTwoFactor()->create();
        $this->jsonClient($user)->request('POST', '/auth/2fa', ['json' => ['code' => '123456']]);
        self::assertResponseStatusCodeSame(401);
    }
}
