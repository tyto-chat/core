<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use OTPHP\TOTP;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TwoFactorEnrollmentTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private const string STATUS = '/api/v1/users/me/2fa';
    private const string SETUP = '/api/v1/users/me/2fa/setup';
    private const string CONFIRM = '/api/v1/users/me/2fa/confirm';
    private const string DISABLE = '/api/v1/users/me/2fa/disable';
    private const string REGENERATE = '/api/v1/users/me/2fa/recovery-codes';

    public function testStatusReportsDisabledByDefault(): void
    {
        $user = UserFactory::createOne();
        $response = $this->jsonClient($user)->request('GET', self::STATUS);
        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertFalse($data['enabled']);
        self::assertSame(0, $data['recoveryCodesRemaining']);
    }

    public function testSetupReturnsSecretAndUri(): void
    {
        $user = UserFactory::createOne();
        $response = $this->jsonClient($user)->request('POST', self::SETUP, ['json' => []]);
        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertNotEmpty($data['secret']);
        self::assertStringStartsWith('otpauth://totp/', $data['otpauthUri']);
        self::assertStringContainsString($data['secret'], $data['otpauthUri']);
    }

    public function testConfirmWithValidCodeEnablesAndReturnsRecoveryCodes(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        $secret = $client->request('POST', self::SETUP, ['json' => []])->toArray()['secret'];

        $code = TOTP::createFromSecret($secret)->now();
        $response = $client->request('POST', self::CONFIRM, ['json' => ['code' => $code]]);
        self::assertResponseIsSuccessful();
        $codes = $response->toArray()['recoveryCodes'];
        self::assertCount(10, $codes);

        $status = $client->request('GET', self::STATUS)->toArray();
        self::assertTrue($status['enabled']);
        self::assertSame(10, $status['recoveryCodesRemaining']);
    }

    public function testConfirmWithWrongCodeIsRejected(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        $client->request('POST', self::SETUP, ['json' => []]);
        $client->request('POST', self::CONFIRM, ['json' => ['code' => '000000']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testConfirmWithoutSetupIsRejected(): void
    {
        $user = UserFactory::createOne();
        $this->jsonClient($user)->request('POST', self::CONFIRM, ['json' => ['code' => '123456']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSetupWhenAlreadyEnabledIsRejected(): void
    {
        $user = UserFactory::new()->withTwoFactor()->create();
        $this->jsonClient($user)->request('POST', self::SETUP, ['json' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->jsonClient()->request('GET', self::STATUS);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDisableWithCorrectPasswordWipesEverything(): void
    {
        $user = UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create();
        $client = $this->jsonClient($user);
        $client->request('POST', self::DISABLE, ['json' => ['currentPassword' => 'Sekret1!']]);
        self::assertResponseStatusCodeSame(204);

        $status = $client->request('GET', self::STATUS)->toArray();
        self::assertFalse($status['enabled']);
        self::assertSame(0, $status['recoveryCodesRemaining']);
    }

    public function testDisableWithWrongPasswordIsRejected(): void
    {
        $user = UserFactory::new()->withPassword('Sekret1!')->withTwoFactor()->create();
        $this->jsonClient($user)->request('POST', self::DISABLE, ['json' => ['currentPassword' => 'wrong']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testDisableWhenNotEnabledIsRejected(): void
    {
        $user = UserFactory::new()->withPassword('Sekret1!')->create();
        $this->jsonClient($user)->request('POST', self::DISABLE, ['json' => ['currentPassword' => 'Sekret1!']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRegenerateReplacesRecoveryCodes(): void
    {
        $user = UserFactory::new()->withPassword('Sekret1!')->create();
        $client = $this->jsonClient($user);
        $secret = $client->request('POST', self::SETUP, ['json' => []])->toArray()['secret'];
        $code = TOTP::createFromSecret($secret)->now();
        $first = $client->request('POST', self::CONFIRM, ['json' => ['code' => $code]])->toArray()['recoveryCodes'];

        $response = $client->request('POST', self::REGENERATE, ['json' => ['currentPassword' => 'Sekret1!']]);
        self::assertResponseIsSuccessful();
        $second = $response->toArray()['recoveryCodes'];
        self::assertCount(10, $second);
        self::assertEmpty(array_intersect($first, $second));

        $status = $client->request('GET', self::STATUS)->toArray();
        self::assertSame(10, $status['recoveryCodesRemaining']);
    }

    public function testTwoFactorEndpointsDenyApiKeys(): void
    {
        $user = UserFactory::new()->withTwoFactor()->create();
        $client = $this->apiKeyClient($user);
        $client->request('GET', self::STATUS);
        self::assertResponseStatusCodeSame(403);
    }
}
